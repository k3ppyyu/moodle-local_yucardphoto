<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Scheduled task: import YU Card photos from the external database.
 *
 * Each run:
 *  1. Connects to the configured external DB (MySQL/PostgreSQL/Oracle).
 *  2. Reads all rows from the source table.
 *  3. For each row, detects MIME type, writes the blob to the Moodle file
 *     system via local_yucardphoto_store_photo(), then upserts the
 *     local_yucardphoto record with the returned URL and path reference.
 *  4. Logs a summary via mtrace().
 *
 * NOTE: Image binary data is NOT stored in the database — only the
 * Moodle pluginfile URL and internal file-area path are persisted.
 *
 * @package   local_yucardphoto
 * @copyright 2026 ED&IT, York University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_yucardphoto\task;

defined('MOODLE_INTERNAL') || die();


/**
 * Nightly import task for YU Card student photos.
 */
class import_yucard_photos extends \core\task\scheduled_task {

    /**
     * Human-readable task name shown in the admin task list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_import_yucard_photos', 'local_yucardphoto');
    }

    /**
     * Execute the import.
     *
     * Throws an exception on fatal errors so Moodle marks the task as failed
     * and retries it on the next cron run.
     */
    public function execute(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/local/yucardphoto/lib.php');

        $dbtype  = get_config('local_yucardphoto', 'yucard_db_type');
        $dbhost  = get_config('local_yucardphoto', 'yucard_db_host');
        $dbname  = get_config('local_yucardphoto', 'yucard_db_name');
        $dbuser  = get_config('local_yucardphoto', 'yucard_db_user');
        $dbpass  = get_config('local_yucardphoto', 'yucard_db_pass');
        $dbtable = get_config('local_yucardphoto', 'yucard_db_table');

        $colsisid     = get_config('local_yucardphoto', 'yucard_col_sisid')     ?: 'student_id';
        $colfirstname = get_config('local_yucardphoto', 'yucard_col_firstname') ?: 'first_name';
        $collastname  = get_config('local_yucardphoto', 'yucard_col_lastname')  ?: 'last_name';
        $colphoto     = get_config('local_yucardphoto', 'yucard_col_photo')     ?: 'photo_blob';
        $colupdated   = get_config('local_yucardphoto', 'yucard_col_updated')   ?: 'last_updated';

        if (empty($dbhost) || empty($dbname) || empty($dbuser) || empty($dbtable)) {
            mtrace('local_yucardphoto: external DB not configured — skipping import.');
            return;
        }

        // ---------- Connect to external database ---------------------------
        $extdb = $this->get_external_db($dbtype, $dbhost, $dbname, $dbuser, $dbpass);
        if (!$extdb) {
            throw new \moodle_exception('local_yucardphoto: could not connect to external YU Card database.');
        }

        mtrace("local_yucardphoto: connected to external DB ({$dbtype}), reading from {$dbtable}…");

        // ---------- Fetch all photo rows -----------------------------------
        $sql  = "SELECT {$colsisid}, {$colfirstname}, {$collastname}, {$colphoto}, {$colupdated} FROM {$dbtable}";
        $rows = $this->query_external($extdb, $dbtype, $sql);

        $inserted  = 0;
        $updated   = 0;
        $unchanged = 0;  // skipped because yucard_lastupdated has not changed
        $skipped   = 0;  // skipped because of missing/invalid data
        $errors    = 0;

        foreach ($rows as $row) {
            $sisid     = trim((string)($row[$colsisid] ?? ''));
            $firstname = trim((string)($row[$colfirstname] ?? ''));
            $lastname  = trim((string)($row[$collastname]  ?? ''));
            $imagedata = $row[$colphoto] ?? null;
            $lastupdated = !empty($row[$colupdated]) ? strtotime((string)$row[$colupdated]) : null;

            if (empty($sisid) || empty($imagedata)) {
                $skipped++;
                continue;
            }

            // Detect mime type from binary magic bytes.
            $mime = $this->detect_mime($imagedata);
            if (!$mime) {
                mtrace("  WARN: unrecognised image type for sisid={$sisid}, skipping.");
                $skipped++;
                continue;
            }
            try {
                $now      = time();
                $existing = $DB->get_record('local_yucardphoto', ['sisid' => $sisid]);

                // On subsequent runs, skip the expensive file-write (and the DB
                // update) when yucard_lastupdated has not changed since the last
                // import.  We only write to the file system when:
                //   (a) no record exists yet (first import), OR
                //   (b) the source timestamp has changed (photo was updated), OR
                //   (c) the source has no timestamp (null) — always re-import
                //       because we cannot tell whether the photo changed.
                $sourcechanged = (
                    !$existing                                         // (a) new record
                    || $lastupdated === null                           // (c) no timestamp
                    || (int)$existing->yucard_lastupdated !== (int)$lastupdated  // (b) changed
                );

                if (!$sourcechanged) {
                    // Photo unchanged — nothing to do for this student.
                    $unchanged++;
                    continue;
                }

                // Write blob to Moodle file system; get back the pluginfile URL.
                // uploaded_by = -1 signals this was written by the scheduled task.
                $fileurl  = local_yucardphoto_store_photo($sisid, $imagedata, $mime, -1);
                $ext      = ($mime === 'image/png') ? 'png' : 'jpg';
                $filepath = "/{$sisid}.{$ext}";

                if ($existing) {
                    $existing->firstname          = $firstname;
                    $existing->lastname           = $lastname;
                    $existing->moodle_file_url    = $fileurl;
                    $existing->yucard_image_path  = $filepath;
                    $existing->yucard_lastupdated = $lastupdated;
                    $existing->uploaded_by        = -1;
                    $existing->timemodified       = $now;
                    $DB->update_record('local_yucardphoto', $existing);
                    $updated++;
                } else {
                    $DB->insert_record('local_yucardphoto', (object)[
                        'sisid'              => $sisid,
                        'firstname'          => $firstname,
                        'lastname'           => $lastname,
                        'moodle_file_url'    => $fileurl,
                        'yucard_image_path'  => $filepath,
                        'yucard_lastupdated' => $lastupdated,
                        'uploaded_by'        => -1,
                        'timecreated'        => $now,
                        'timemodified'       => $now,
                    ]);
                    $inserted++;
                }
            } catch (\Throwable $e) {
                mtrace("  ERROR processing sisid={$sisid}: " . $e->getMessage());
                $errors++;
            }
        }

        $this->close_external($extdb, $dbtype);

        mtrace("local_yucardphoto: import complete — inserted={$inserted}, updated={$updated}, unchanged={$unchanged}, skipped={$skipped}, errors={$errors}.");
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Open a PDO or OCI connection to the external database.
     *
     * @param  string      $dbtype  mysqli|pgsql|oci
     * @param  string      $host
     * @param  string      $dbname
     * @param  string      $user
     * @param  string      $pass
     * @return \PDO|\resource|false  Connection handle, or false on failure.
     */
    private function get_external_db(string $dbtype, string $host, string $dbname, string $user, string $pass) {
        try {
            if ($dbtype === 'oci') {
                // Oracle via OCI8 extension (matches existing yorktasks pattern).
                if (!function_exists('oci_connect')) {
                    mtrace('local_yucardphoto: OCI8 extension not available.');
                    return false;
                }
                $conn = oci_connect($user, $pass, $dbname);
                if (!$conn) {
                    $e = oci_error();
                    mtrace('local_yucardphoto OCI connect error: ' . ($e['message'] ?? 'unknown'));
                    return false;
                }
                return $conn;
            }

            // MySQL or PostgreSQL via PDO.
            $driver = ($dbtype === 'pgsql') ? 'pgsql' : 'mysql';
            $dsn    = ($dbtype === 'pgsql')
                ? "pgsql:host={$host};dbname={$dbname}"
                : "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            return $pdo;

        } catch (\Throwable $e) {
            mtrace('local_yucardphoto: external DB connection failed — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Run a SELECT query and return all rows as an associative array.
     *
     * @param  mixed  $conn    Connection handle (PDO or OCI resource).
     * @param  string $dbtype
     * @param  string $sql
     * @return array
     */
    private function query_external($conn, string $dbtype, string $sql): array {
        if ($dbtype === 'oci') {
            $stid = oci_parse($conn, $sql);
            oci_execute($stid);
            $rows = [];
            while ($row = oci_fetch_assoc($stid)) {
                // OCI8 returns column names in UPPERCASE — normalise to lower.
                $rows[] = array_change_key_case($row, CASE_LOWER);
            }
            oci_free_statement($stid);
            return $rows;
        }

        // PDO.
        $stmt = $conn->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Close the external database connection.
     *
     * @param  mixed  $conn
     * @param  string $dbtype
     */
    private function close_external($conn, string $dbtype): void {
        if ($dbtype === 'oci') {
            oci_close($conn);
        }
        // PDO connections close automatically when the variable goes out of scope.
    }

    /**
     * Detect MIME type from the first few bytes (magic bytes) of image data.
     *
     * @param  string $data Raw binary.
     * @return string|false  'image/jpeg', 'image/png', or false if unrecognised.
     */
    private function detect_mime(string $data) {
        if (substr($data, 0, 2) === "\xFF\xD8") {
            return 'image/jpeg';
        }
        if (substr($data, 0, 4) === "\x89PNG") {
            return 'image/png';
        }
        return false;
    }
}
