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
 * Scheduled task: import YU Card photos from the external Oracle database.
 *
 * Each run:
 *  1. Connects to the Oracle YU Card DB via OCI8 using the configured TNS string.
 *  2. Fetches metadata only (SISID + PHOTOMODIFIEDDATE, no BLOBs) from
 *     envision.vw_yk_eclass_photos to determine which rows need updating.
 *  3. Looks up firstname/lastname from mdl_user.idnumber (joined on SISID = idnumber).
 *  4. For each changed row, fetches the PHOTO BLOB individually in its own
 *     short-lived query to avoid Oracle session timeouts (ORA-03114).
 *  5. Detects MIME type, writes the blob to the Moodle file system, and
 *     persists the file URL in mdl_local_yucardphoto.
 *
 * Oracle connection details (configured in Admin > Plugins > local_yucardphoto):
 *   yucard_db_type     = oci
 *   yucard_db_tns      = yucarddb3qa.yorku.yorku.ca:1521/bbts
 *   yucard_db_schema   = envision
 *   yucard_db_user     = <db username>
 *   yucard_db_pass     = <db password>
 *   yucard_db_table    = vw_yk_eclass_photos
 *   yucard_col_sisid   = SISID
 *   yucard_col_photo   = PHOTO
 *   yucard_col_updated = PHOTOMODIFIEDDATE
 *
 * NOTE: Image binary data is NOT stored in the Moodle database — only the
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

        // ── Config ───────────────────────────────────────────────────────
        $dbtype   = get_config('local_yucardphoto', 'yucard_db_type')    ?: 'oci';
        // TNS connect string: easy-connect format  host:port/service
        // or a full TNS alias defined in tnsnames.ora.
        // Example: yucarddb3qa.yorku.yorku.ca:1521/bbts
        $dbtns    = get_config('local_yucardphoto', 'yucard_db_tns')     ?: '';
        $dbschema = get_config('local_yucardphoto', 'yucard_db_schema')  ?: 'envision';
        $dbuser   = get_config('local_yucardphoto', 'yucard_db_user')    ?: '';
        $dbpass   = get_config('local_yucardphoto', 'yucard_db_pass')    ?: '';
        $dbtable  = get_config('local_yucardphoto', 'yucard_db_table')   ?: 'vw_yk_eclass_photos';

        // Source column names — configurable so they can be adjusted without code changes.
        $colsisid   = get_config('local_yucardphoto', 'yucard_col_sisid')   ?: 'SISID';
        $colphoto   = get_config('local_yucardphoto', 'yucard_col_photo')   ?: 'PHOTO';
        $colupdated = get_config('local_yucardphoto', 'yucard_col_updated') ?: 'PHOTOMODIFIEDDATE';

        if (empty($dbtns) || empty($dbuser) || empty($dbtable)) {
            mtrace('local_yucardphoto: external DB not configured — skipping import.');
            return;
        }

        // ── Parse host/port from easy-connect TNS string ──────────────────
        // Expected format: host:port/service  e.g. yucarddb3qa.yorku.yorku.ca:1521/bbts
        // Falls back to port 1521 if not parseable (e.g. a bare TNS alias).
        $tcphost = $dbtns;
        $tcpport = 1521;
        if (preg_match('/^([^:\/]+):(\d+)(?:\/.*)?$/', $dbtns, $m)) {
            $tcphost = $m[1];
            $tcpport = (int)$m[2];
        } elseif (strpos($dbtns, '/') !== false) {
            // host/service with no port
            $tcphost = explode('/', $dbtns)[0];
        }
        // Attempt a quick TCP socket to the Oracle host/port before invoking
        // oci_connect(), which has no configurable timeout and will block for
        // the full OCI_DEFAULT_TIMEOUT (often 60s) when the host is unreachable.
        // This gives a fast, human-readable error for the common case of
        // running the task locally without VPN access to the Oracle DB.
        if (!$this->check_tcp_reachable($dbtns, $tcphost, $tcpport)) {
            mtrace("local_yucardphoto: Oracle host {$tcphost}:{$tcpport} is not reachable (TCP connect failed).");
            mtrace("  → If running locally, connect to the York VPN first.");
            mtrace("  → TNS string configured: {$dbtns}");
            mtrace("  → Task skipped — no data was modified.");
            // Return rather than throw so the task is not permanently disabled by Moodle's
            // fail-delay backoff. It will simply run again at the next scheduled time.
            return;
        }

        // ── Connect to Oracle ─────────────────────────────────────────────
        $extdb = $this->get_external_db($dbtype, $dbtns, $dbuser, $dbpass);
        if (!$extdb) {
            throw new \moodle_exception('local_yucardphoto: could not connect to Oracle YU Card database. ' .
                'Check that the OCI8 PHP extension is installed in the Docker container and that the ' .
                'TNS connect string, username and password are correct in Admin > Plugins > local_yucardphoto.');
        }

        mtrace("local_yucardphoto: connected to Oracle ({$dbtns}), reading metadata from {$dbschema}.{$dbtable}…");

        // ── Phase 1: Fetch metadata only (SISID + PHOTOMODIFIEDDATE, NO BLOB) ──
        // This query is lightweight — no binary data is transferred.
        // We use this to determine which rows actually need updating before
        // we fetch any BLOBs, avoiding holding a large cursor open.
        $colsisidlc   = strtolower($colsisid);
        $colupdatedlc = strtolower($colupdated);

        // TO_CHAR converts the Oracle DATE column to an unambiguous ISO string
        // so strtotime() parses it correctly regardless of Oracle NLS session settings.
        // Without this, Oracle may return "14-APR-26" which strtotime() cannot parse,
        // causing $lastupdated to be null and every row to appear as needing an update.
        $metasql  = "SELECT {$colsisid}, TO_CHAR({$colupdated}, 'YYYY-MM-DD HH24:MI:SS') AS {$colupdated}
                       FROM {$dbschema}.{$dbtable};
        $metamap  = $this->query_metadata($extdb, $dbtype, $metasql, $colsisidlc, $colupdatedlc);

        mtrace("local_yucardphoto: received " . count($metamap) . " rows of metadata.");

        // ── Phase 2: Pre-load Moodle user name lookup (idnumber → firstname/lastname) ──
        $moodleusers = $DB->get_records_sql(
            "SELECT idnumber, firstname, lastname
               FROM {user}
              WHERE deleted = 0
                AND idnumber <> ''",
            []
        );
        $userbyid = [];
        foreach ($moodleusers as $u) {
            $userbyid[$u->idnumber] = $u;
        }
        mtrace("local_yucardphoto: loaded " . count($userbyid) . " Moodle users for name lookup.");

        // ── Phase 3: Determine which SISIDs need a BLOB fetch ──────────────
        // Compare PHOTOMODIFIEDDATE against what we already have in Moodle DB.
        // If lastupdated is null (date could not be parsed) we treat the row as
        // unchanged to avoid re-downloading every photo on every run.
        $needsupdate = []; // sisid => lastupdated (unix ts)
        foreach ($metamap as $sisid => $lastupdated) {
            $existing = $DB->get_record('local_yucardphoto', ['sisid' => $sisid], 'id,yucard_lastupdated');
            if (!$existing) {
                // Never imported — always fetch.
                $needsupdate[$sisid] = $lastupdated;
            } else if ($lastupdated !== null && (int)$existing->yucard_lastupdated !== (int)$lastupdated) {
                // Date changed — fetch updated photo.
                $needsupdate[$sisid] = $lastupdated;
            }
            // else: lastupdated is null (unparseable) OR date matches — skip.
        }

        $total     = count($metamap);
        $unchanged = $total - count($needsupdate);
        mtrace("local_yucardphoto: {$unchanged} photos unchanged, " . count($needsupdate) . " need updating.");

        $inserted  = 0;
        $updated   = 0;
        $skipped   = 0;
        $errors    = 0;
        $nousers   = 0;

        // ── Phase 4: Fetch each BLOB individually for changed rows ──────────
        // Each BLOB is fetched in its own short-lived query so Oracle never
        // has to hold a large deferred cursor open across hundreds of loads.
        foreach ($needsupdate as $sisid => $lastupdated) {
            // Bind the SISID parameter to avoid SQL injection and let Oracle reuse the parse.
            $blobsql = "SELECT {$colphoto}
                          FROM {$dbschema}.{$dbtable}
                         WHERE {$colsisid} = :sisid";

            $imagedata = $this->fetch_single_blob($extdb, $dbtype, $blobsql, $sisid);

            if (empty($imagedata)) {
                mtrace("  WARN: empty BLOB for sisid={$sisid}, skipping.");
                $skipped++;
                continue;
            }

            // Look up firstname/lastname from Moodle.
            $firstname = '';
            $lastname  = '';
            if (isset($userbyid[$sisid])) {
                $firstname = $userbyid[$sisid]->firstname;
                $lastname  = $userbyid[$sisid]->lastname;
            } else {
                $nousers++;
                mtrace("  INFO: no Moodle user with idnumber={$sisid} — photo imported without name.");
            }

            // Detect MIME from magic bytes.
            $mime = $this->detect_mime($imagedata);
            if (!$mime) {
                mtrace("  WARN: unrecognised image type for sisid={$sisid}, skipping.");
                $skipped++;
                continue;
            }

            try {
                $now      = time();
                $existing = $DB->get_record('local_yucardphoto', ['sisid' => $sisid]);


                // Write blob to Moodle file system.
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

        mtrace("local_yucardphoto: import complete — inserted={$inserted}, updated={$updated}, " .
               "unchanged={$unchanged}, skipped={$skipped}, nousers={$nousers}, errors={$errors}.");
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Open an OCI8 connection to Oracle using a TNS connect string.
     *
     * The TNS string can be either:
     *   - Easy Connect: host:port/service_name  (e.g. yucarddb3qa.yorku.yorku.ca:1521/bbts)
     *   - A TNS alias defined in the server's tnsnames.ora
     *
     * For non-Oracle databases (MySQL/PostgreSQL) the original PDO path is retained
     * but is not expected to be used for the YU Card import.
     *
     * @param  string $dbtype  oci | mysqli | pgsql
     * @param  string $tns     TNS connect string or alias (Oracle), or host (MySQL/PG)
     * @param  string $user
     * @param  string $pass
     * @return resource|false  OCI8 connection resource, PDO object, or false on failure.
     */
    private function get_external_db(string $dbtype, string $tns, string $user, string $pass) {
        try {
            if ($dbtype === 'oci') {
                if (!function_exists('oci_connect')) {
                    mtrace('local_yucardphoto: OCI8 PHP extension is not available.');
                    mtrace('  → The ext-oci8 extension must be installed in the Docker container.');
                    mtrace('  → Install Oracle Instant Client, then run: pecl install oci8 && docker-php-ext-enable oci8');
                    mtrace('  → See: https://www.oracle.com/database/technologies/instant-client/downloads.html');
                    return false;
                }
                // Use oci_connect with the TNS string as the connection identifier.
                // Character set AL32UTF8 ensures Unicode photo metadata is handled correctly.
                $conn = oci_connect($user, $pass, $tns, 'AL32UTF8');
                if (!$conn) {
                    $e = oci_error();
                    mtrace('local_yucardphoto OCI connect error: ' . ($e['message'] ?? 'unknown'));
                    return false;
                }
                return $conn;
            }

        } catch (\Throwable $e) {
            mtrace('local_yucardphoto: external DB connection failed — ' . $e->getMessage());
            return false;
        }
        // Only Oracle (OCI) is supported for the YU Card import.
        mtrace("local_yucardphoto: unsupported DB type '{$dbtype}' — only 'oci' is supported.");
        return false;
    }

    /**
     * Fetch metadata rows (SISID + PHOTOMODIFIEDDATE) without loading any BLOBs.
     *
     * Returns an associative array keyed by sisid with unix-timestamp values.
     * This completes quickly since no binary data is transferred.
     *
     * @param  mixed  $conn         OCI8 resource.
     * @param  string $dbtype
     * @param  string $sql          SELECT with only the two non-BLOB columns.
     * @param  string $colsisidlc   Lowercase column name for SISID.
     * @param  string $colupdatedlc Lowercase column name for PHOTOMODIFIEDDATE.
     * @return array  ['sisid' => lastupdated_unix_ts|null, ...]
     */
    private function query_metadata($conn, string $dbtype, string $sql,
                                    string $colsisidlc, string $colupdatedlc): array {
        $result = [];
        if ($dbtype === 'oci') {
            $stid = oci_parse($conn, $sql);
            oci_execute($stid, OCI_COMMIT_ON_SUCCESS);
            while ($row = oci_fetch_assoc($stid)) {
                $row     = array_change_key_case($row, CASE_LOWER);
                $sisid   = trim((string)($row[$colsisidlc] ?? ''));
                $rawdate = $row[$colupdatedlc] ?? null;
                if (empty($sisid)) {
                    continue;
                }
                $lastupdated = null;
                if (!empty($rawdate)) {
                    $ts = strtotime((string)$rawdate);
                    $lastupdated = ($ts !== false) ? $ts : null;
                    if ($ts === false) {
                        mtrace("  WARN: could not parse date '{$rawdate}' for sisid={$sisid} — row treated as unchanged.");
                    }
                }
                $result[$sisid] = $lastupdated;
            }
            oci_free_statement($stid);
        }
        return $result;
    }

    /**
     * Fetch a single BLOB for one SISID, load it immediately, and return the binary string.
     *
     * Using a per-row targeted query with a bound parameter keeps each Oracle
     * cursor open for only the duration of a single row fetch — this prevents
     * the ORA-03114 "not connected" error that occurs when a long-lived deferred
     * cursor times out while hundreds of BLOBs are being loaded sequentially.
     *
     * @param  mixed  $conn    OCI8 resource.
     * @param  string $dbtype
     * @param  string $sql     SELECT with :sisid bind variable, e.g.
     *                          SELECT PHOTO FROM schema.table WHERE SISID = :sisid
     * @param  string $sisid   The student ID to bind.
     * @return string|false    Binary image data, or false on failure / empty BLOB.
     */
    private function fetch_single_blob($conn, string $dbtype, string $sql, string $sisid) {
        if ($dbtype !== 'oci') {
            return false;
        }
        $stid = oci_parse($conn, $sql);
        oci_bind_by_name($stid, ':sisid', $sisid);
        oci_execute($stid, OCI_COMMIT_ON_SUCCESS);
        $row = oci_fetch_assoc($stid);
        oci_free_statement($stid);

        if (!$row) {
            return false;
        }
        $row  = array_change_key_case($row, CASE_LOWER);
        // The BLOB column will be the first (and only) value.
        $blob = reset($row);
        if (empty($blob)) {
            return false;
        }
        // Load OCI-Lob object immediately while the connection is still fresh.
        if (is_object($blob) && method_exists($blob, 'load')) {
            $data = @$blob->load();
            return ($data !== false && $data !== '') ? $data : false;
        }
        return is_string($blob) && $blob !== '' ? $blob : false;
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
        // PDO closes automatically when the variable goes out of scope.
    }

    /**
     * Detect MIME type from magic bytes.
     *
     * @param  string $data Raw binary image data.
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

    /**
     * Test whether the Oracle host is reachable via a plain TCP connect.
     *
     * Called before oci_connect() to avoid the 60-second OCI timeout when
     * running locally without VPN access to the Oracle network.
     *
     * Returns true immediately for TNS alias strings that cannot be parsed
     * into a host:port (we have to let OCI try in that case).
     *
     * @param  string $tns      Original configured TNS string (for logging).
     * @param  string $tcphost  Resolved hostname to test.
     * @param  int    $tcpport  Port to test (default 1521).
     * @param  int    $timeout  TCP connect timeout in seconds (default 5).
     * @return bool
     */
    private function check_tcp_reachable(string $tns, string $tcphost, int $tcpport, int $timeout = 5): bool {
        // If tcphost still equals the full TNS string (unparseable alias), skip the check.
        if ($tcphost === $tns) {
            return true;
        }
        $sock = @fsockopen($tcphost, $tcpport, $errno, $errstr, $timeout);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }
}
