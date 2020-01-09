<?php

/**
 * @package   block_afs
 * @copyright 2019 Swarthmore College ITS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_afs\task;

class scan_files extends \core\task\scheduled_task
{

    /**
     * @description This is a helper function that returns the name of the filescan task
     * @return string
     */
    public function get_name()
    {
        return get_string('task:scan', 'block_afs');
    }

    /**
     * @description Presists a result row to the database
     * @param $conn - The moodle db connection
     * @param $row - The database row
     */
    private static function save_filescan_results($conn, $row)
    {
        $record = $conn->get_record("block_afs", array('contenthash' => $row->contenthash));

        if ($record) {
            $row->id = $record->id;

            // If this is a failure, update the statuscode
            if ($row->checked == 0) {
                $row->statuscode = $record->statuscode + 1;
            }

            $sql = $conn->update_record("block_afs", $row);
            $sql
                    ? mtrace("Updated record for " . $row->contenthash)
                    : mtrace("Could not update record " . $row->contenthash);

        } else {

            if ($row->checked == 0) {
                $row->statuscode = 1;
            }

            $sql = $conn->insert_record("block_afs", $row, $returnid = true, $bulk = false);
            $sql ? mtrace("Inserted record") : mtrace("Could not insert record");
        }
    }

    /**
     * @description Find PDF files in course materials (not student files, stamps, etc) that haven't already been scanned
     * @param $conn - The Moodle DB connection object
     * @return array|bool
     */
    private function get_files($conn)
    {
        // Grab some of the plugin variables from the config, we need these while constructing the query
        $max_files_to_check = (int) get_config("block_afs", "numfilespercron");
        $max_retries = (int) get_config("block_afs", "maxretries");

        $query = "SELECT afs.contenthash 
            FROM {files} f, {context} c
            WHERE c.id = f.contextid 
                AND c.contextlevel = 70 
                AND f.filesize <> 0 
                AND f.mimetype = 'application/pdf'
                AND f.component <> 'assignfeedback_editpdf' 
                AND f.filearea <> 'stamps'
                AND f.contenthash NOT IN (SELECT contenthash FROM {block_afs} where checked=1  or (checked <> 1 and status='error' and statuscode >= $max_retries )) 	
            ORDER BY f.id DESC
            LIMIT $max_files_to_check";

        $query = "SELECT afs.contenthash
            FROM {block_afs} afs
        ";

        // LEFT JOIN {context} c ON f.contextid = c.id
        // LEFT JOIN {block_afs} afs ON f
        // WHERE c.contextlevel = 70
        // LIMIT $max_files_to_check

        print_r($query);

        // make the query and return an array of the files
        $content_hashes = $conn->get_records_sql($query);

        print_r($content_hashes);

        // Only continue if there are content hashes
        if (!$content_hashes) {
            mtrace("No files found");
            return false;
        }

        // TODO: There's got to be a better way to do this...
        // Set up an array containing the latest contenthashes
        $hash_array = array();

        foreach ($content_hashes as $i=>$c) {
            $hash_array[$i] = $c->contenthash;
        }

        $comma_separated_content_hashes = implode("','", $hash_array);
        $comma_separated_content_hashes = "'" . $comma_separated_content_hashes . "'";

        $query = "SELECT  f.contenthash, f.pathnamehash 
            FROM {files} f
            WHERE f.contenthash IN ($comma_separated_content_hashes)
            GROUP BY f.contenthash, f.pathnamehash";

        $files = $conn->get_records_sql($query);

        // Either return an array of the files, or return an empty array.
        if ($files) {
            return $files;
        } else {
            mtrace("No files found!");
            return array();
        }
    }

    /**
     * @description Sends supplied files to the filescan server and handles the responses
     * @param $conn - The Moodle DB connection object
     * @param $files
     * @return bool
     */
    private function process_requests($conn, $files)
    {

        // If there are no files found, don't bother proceeding
        if (sizeof($files) == 0) {
            return true;
        }

        // Get the file storage , we will need this for getting the files by their hash
        $fs = get_file_storage();

        foreach ($files as $f) {

            $file = $fs->get_file_by_hash($f->pathnamehash);
            $file_contents = $file->get_content();
            $file_content_hash = $f->contenthash;

            // in bytes
            // TODO: make max filesize a config option
            $max_filesize = (int) get_config("block_afs", "maxfilesize");
            $filesize = (int) $file->get_filesize();

            // If there are no file contents, save the results to the db here and break out of the
            // current iteration
            // Check the filesize, if it is greater than a certain limit, don't send the request
            if (!$file_contents) {
                $row = new \stdClass();
                $row->timechecked = date("Y-m-d H:i:s");
                $row->contenthash = $file_content_hash;
                $row->status = "error";
                $row->checked = 0;
                self::save_filescan_results($conn, $row);
                continue;
            }

            // If the filesize is greater than the max filesize, skip the current loop iter
            if ($filesize > $max_filesize) {
                mtrace("Cannot process file. Exceeds max file size");
                continue;
            }

            // Set up the request handler, which is an instance of Moodle's curl implementation
            // See the undocumented curl docs in the Moodle source code
            // https://github.com/moodle/moodle/blob/master/lib/filelib.php#L2972
            $request = new \curl();

            // Set up the request headers
            $headers = array(
                "cache-control: no-cache",
                "Content-Type: multipart/form-data",
                "Accept: application/json"
            );

            // Set up curl options
            $curl_opts = array(
                "curlopt_httpheader" => $headers,
                "curlopt_timeout" => "120L",
                "curlopt_followlocation" => true,
                "curlopt_header" => false
            );

            $params = array(
                "upfile" => $file,
                "id" => $f->contenthash
            );

            // Make the request and store it in the response variable
            $response = $request->post($this->get_filescan_endpoint(), $params, $curl_opts);

            // Get the curl info from the request, we need the http status codes from it
            $request_info = $request->get_info();

            // Create the row object here and assign the contenthash to it
            $row = new \stdClass();
            $row->contenthash = $file_content_hash;

            // If we don't get a 200 response, output the error to moodle via mtrace and
            // mark the row with an error. Save the results and exit out of the current loop
            if ($request_info["http_code"] != 200) {

                mtrace("Did not receive 200 status from filescan server when requesting scan for " . $row->contenthash);
                mtrace(var_dump($request_info));

                // Update the row with an error status and break out of the loop
                $row->status = "error";
                $row->checked = 0;
                self::save_filescan_results($conn, $row);
                continue;
            }

            // Decode the response into an array of arrays
            $results = json_decode($response, true);

            // Handle the results
            $row->timechecked = date("Y-m-d H:i:s");
            $row->hastext = (int) $results["application/json"]["hasText"];
            // There might be a better way to do this, the server will return the actual
            // string for title and language, and casting that to an int doesn't work
            // so this is the way it is for now
            $row->hastitle = $results["application/json"]["title"] ? 1 : 0;
            $row->haslanguage = $results["application/json"]["language"] ? 1 : 0;
            $row->hasoutline = (int) $results["application/json"]["hasOutline"];
            $row->checked = 1;
            $row->pagecount = (int) $results["application/json"]["numPages"];

            // Determine if the file is accessible or not
            // no text -> fail
            // has text, title, language, and outline -> pass 🔥🔥🔥
            // has text but is missing other -> check
            $row->status = ($row->hastitle && $row->haslanguage && $row->hasoutline)
                ? ($row->hastext ? "pass" : "fail")
                : "check";

            // Update the database with the results
            self::save_filescan_results($conn, $row);

        }

        return true;
    }

    /**
     * @description Returns the filescan server endpoint for scanning a file
     * @return string
     */
    private function get_filescan_endpoint()
    {
        $api_url = get_config('block_afs', 'apiurl');
        if (empty($api_url)) {
            // TODO: If a default filescan server URL is every implemented, return it here instead of throwing an error
            throw new \runtimeexception("Moodle filescan API URL is missing.");
        } else {
            return $api_url . "/v1/file";
        }
    }

    /**
     * @description Runs the scan_files task
     * @return bool
     */
    public function execute()
    {
        global $CFG, $DB;
        require_once($CFG->libdir . '/filelib.php');

        mtrace("Filescan cron script is running.");

        // Get the files and process them
        $files = self::get_files($DB);
        self::process_requests($DB, $files);

        mtrace("Done!");
        return true;
    }

}
