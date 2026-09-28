<?php
/**
 * Migration registrationfields (FMM) -> native customer.company / customer.siret
 *
 * The registrationfields module stores its custom fields in its own table
 * (`<prefix>fmm_registration_userdata`). With the B2B mode of PrestaShop, the
 * company and the SIRET live in the native `customer` columns, which the
 * awb2bcustomer and awcustomerapproval modules rely on.
 *
 * For every non deleted customer, the value of the two custom fields is copied
 * into the native column when the latter is empty:
 *
 *   native empty, custom value present            ->  copied
 *   native filled with the same value             ->  nothing to do
 *   native filled with a different value          ->  untouched, listed (conflict)
 *   custom SIRET longer than 14 chars once cleaned ->  column untouched (does not fit),
 *                                                     raw value appended to the private
 *                                                     note of the customer (idempotent)
 *   no custom value                               ->  nothing to do
 *
 * SIRET clean-up: only letters and digits are kept (spaces, dots, dashes and
 * invisible characters removed), upper-cased (EU VAT numbers).
 *
 * Nothing else is written: the registrationfields tables and configuration are
 * left untouched (the module is meant to be uninstalled afterwards).
 *
 * Usage (from the PrestaShop root, inside the PHP container):
 *   php modules/awb2bcustomer/bin/migrate-registrationfields.php            # dry run
 *   php modules/awb2bcustomer/bin/migrate-registrationfields.php --apply    # apply
 *
 * Options:
 *   --apply              Apply the changes. Without it, nothing is written (dry run).
 *   --company-field=ID   registrationfields field holding the company (default 12).
 *   --siret-field=ID     registrationfields field holding the SIRET (default 13).
 *   --limit=N            Number of customers listed in the details (default 20, 0 = all).
 *
 * When applied, two audit files are written in var/logs/:
 *   awb2bcustomer-migration-<date>.sql  rollback UPDATE statements (previous native values)
 *   awb2bcustomer-migration-<date>.csv  every account updated (id, email, before/after values)
 *
 * @author    Axelweb <contact@axelweb.fr>
 * @copyright 2026 Axelweb
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */
if (PHP_SAPI !== 'cli') {
    exit('This script must be run from the command line.' . PHP_EOL);
}

$root = realpath(__DIR__ . '/../../..');
if (!$root || !is_file($root . '/config/config.inc.php')) {
    fwrite(STDERR, 'PrestaShop root not found from ' . __DIR__ . PHP_EOL);
    exit(1);
}

require $root . '/config/config.inc.php';

// --- Options -----------------------------------------------------------------
$options = getopt('', ['apply', 'company-field::', 'siret-field::', 'limit::', 'help']);
if (isset($options['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 2300) . PHP_EOL;
    exit(0);
}
$apply = isset($options['apply']);
$companyFieldId = isset($options['company-field']) ? (int) $options['company-field'] : 12;
$siretFieldId = isset($options['siret-field']) ? (int) $options['siret-field'] : 13;
$limit = isset($options['limit']) ? (int) $options['limit'] : 20;

const SIRET_MAX_LENGTH = 14;
const COMPANY_MAX_LENGTH = 255;

$db = Db::getInstance();
$prefix = _DB_PREFIX_;
$dataTable = $prefix . 'fmm_registration_userdata';

// --- Preconditions -----------------------------------------------------------
if (!$db->executeS('SHOW TABLES LIKE "' . pSQL($dataTable) . '"')) {
    fwrite(STDERR, "Table $dataTable not found: nothing to migrate." . PHP_EOL);
    exit(1);
}

$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$fieldNames = [];
foreach ([$companyFieldId, $siretFieldId] as $fieldId) {
    $fieldNames[$fieldId] = (string) $db->getValue('SELECT field_name FROM `' . $prefix . 'fmm_registration_fields_lang` WHERE id_custom_field = ' . $fieldId . ' AND id_lang = ' . $idLang);
    if ($fieldNames[$fieldId] === '') {
        fwrite(STDERR, "registrationfields field #$fieldId not found." . PHP_EOL);
        exit(1);
    }
}

echo str_repeat('=', 72) . PHP_EOL;
echo 'Migration registrationfields -> native customer columns  [' . ($apply ? 'APPLY' : 'DRY RUN') . ']' . PHP_EOL;
printf("Company : field #%d (%s) -> customer.company\n", $companyFieldId, $fieldNames[$companyFieldId]);
printf("SIRET   : field #%d (%s) -> customer.siret\n", $siretFieldId, $fieldNames[$siretFieldId]);
echo str_repeat('=', 72) . PHP_EOL . PHP_EOL;

// --- Helpers -----------------------------------------------------------------
$cleanCompany = static function (?string $value): string {
    return trim((string) $value);
};

// Keeps letters and digits only: spaces, dots, dashes, but also invisible
// characters pasted from other software (non-breaking spaces, direction marks...).
$cleanSiret = static function (?string $value): string {
    $compact = (string) preg_replace('/[^\p{L}\p{N}]/u', '', (string) $value);

    return mb_strtoupper($compact);
};

// Latest value of a custom field per customer (the table has no unique key we can rely on).
$latestValue = static function (int $fieldId) use ($dataTable): string {
    return '(SELECT d.value FROM `' . $dataTable . '` d
             WHERE d.id_custom_field = ' . $fieldId . ' AND d.id_customer = c.id_customer
             ORDER BY d.value_id DESC LIMIT 1)';
};

// --- Load --------------------------------------------------------------------
$rows = $db->executeS('
    SELECT c.id_customer, c.email, c.firstname, c.lastname, c.company, c.siret, c.note,
           ' . $latestValue($companyFieldId) . ' AS rf_company,
           ' . $latestValue($siretFieldId) . ' AS rf_siret
    FROM `' . $prefix . 'customer` c
    WHERE c.deleted = 0 AND c.is_guest = 0
    ORDER BY c.id_customer
') ?: [];

$updates = [];      // id_customer => ['company' => ?, 'siret' => ?, 'row' => row]
$conflicts = [];    // [row, field, native, custom]
$tooLong = [];      // [row, field, custom (cleaned), custom (raw)]

// Too long values are kept in the private note of the customer (back-office
// customer page), appended to the existing note if any. The prefix is also the
// marker that makes the script idempotent.
const NOTE_PREFIX = ['company' => 'Société saisie à l\'inscription', 'siret' => 'SIRET saisi à l\'inscription'];
$noteText = static function (string $field, string $raw): string {
    return NOTE_PREFIX[$field] . ' (non conforme, non repris dans le champ ' . $field . ') : ' . $raw;
};
$noteAlreadyThere = static function (array $row, string $field): bool {
    return str_contains((string) $row['note'], NOTE_PREFIX[$field]);
};
$stats = ['customers' => count($rows), 'no_value' => 0, 'same' => 0, 'company' => 0, 'siret' => 0];

foreach ($rows as $row) {
    $custom = [
        'company' => $cleanCompany($row['rf_company']),
        'siret' => $cleanSiret($row['rf_siret']),
    ];
    if ($custom['company'] === '' && $custom['siret'] === '') {
        ++$stats['no_value'];
        continue;
    }

    foreach (['company' => COMPANY_MAX_LENGTH, 'siret' => SIRET_MAX_LENGTH] as $field => $maxLength) {
        $value = $custom[$field];
        if ($value === '') {
            continue;
        }
        $native = trim((string) $row[$field]);
        if ($native !== '') {
            if (mb_strtoupper($native) === mb_strtoupper($value)) {
                ++$stats['same'];
            } else {
                $conflicts[] = [$row, $field, $native, $value];
            }
            continue;
        }
        if (mb_strlen($value) > $maxLength) {
            $tooLong[] = [$row, $field, $value, trim((string) $row['rf_' . $field])];
            continue;
        }
        $updates[$row['id_customer']]['row'] = $row;
        $updates[$row['id_customer']][$field] = $value;
        ++$stats[$field];
    }
}

// --- Report ------------------------------------------------------------------
printf("Customers (not deleted, not guest)         : %5d\n", $stats['customers']);
printf("  without any custom value                 : %5d  -> nothing to do\n", $stats['no_value']);
printf("  native column already holding the value  : %5d  -> nothing to do\n", $stats['same']);
printf("  native column holding ANOTHER value      : %5d  -> untouched, see list (conflicts)\n", count($conflicts));
$notesToWrite = array_filter($tooLong, static fn (array $item): bool => !$noteAlreadyThere($item[0], $item[1]));
$notesExisting = array_filter($notesToWrite, static fn (array $item): bool => trim((string) $item[0]['note']) !== '');
printf("  custom value too long for the column     : %5d  -> column untouched, value kept in the private note (%d to write, %d appended to an existing note)\n", count($tooLong), count($notesToWrite), count($notesExisting));
printf("Accounts to update                         : %5d  (company: %d, siret: %d)\n\n", count($updates), $stats['company'], $stats['siret']);

$printUpdates = static function (array $updates, int $limit): void {
    $shown = 0;
    foreach ($updates as $update) {
        if ($limit > 0 && $shown >= $limit) {
            printf("    ... and %d more\n", count($updates) - $shown);
            break;
        }
        $row = $update['row'];
        printf("    #%-6d %-40s company=%-30s siret=%s\n",
            $row['id_customer'],
            mb_substr($row['email'], 0, 40),
            mb_substr($update['company'] ?? '(unchanged)', 0, 30),
            $update['siret'] ?? '(unchanged)'
        );
        ++$shown;
    }
};

echo 'Accounts to update:' . PHP_EOL;
$printUpdates($updates, $limit);
echo PHP_EOL;

if (!empty($conflicts)) {
    echo 'Conflicts (native value kept):' . PHP_EOL;
    foreach ($conflicts as [$row, $field, $native, $value]) {
        printf("    #%-6d %-40s %-7s native=%s | custom=%s\n", $row['id_customer'], mb_substr($row['email'], 0, 40), $field, $native, $value);
    }
    echo PHP_EOL;
}

if (!empty($tooLong)) {
    echo 'Custom values too long for the native column (kept in the private note):' . PHP_EOL;
    $shown = 0;
    foreach ($tooLong as [$row, $field, $value, $raw]) {
        if ($limit > 0 && $shown >= $limit) {
            printf("    ... and %d more (see the CSV once applied)\n", count($tooLong) - $shown);
            break;
        }
        printf("    #%-6d %-40s %-7s %-40s %s\n",
            $row['id_customer'],
            mb_substr($row['email'], 0, 40),
            $field,
            mb_substr($raw, 0, 40),
            $noteAlreadyThere($row, $field) ? '(already in the note)' : (trim((string) $row['note']) !== '' ? '(appended to: ' . mb_substr(trim($row['note']), 0, 30) . '...)' : '')
        );
        ++$shown;
    }
    echo PHP_EOL;
}

// --- Apply -------------------------------------------------------------------
if (!$apply) {
    echo 'Dry run: nothing written. Run again with --apply to copy the values and write the notes listed above.' . PHP_EOL;
    exit(0);
}

$logDir = $root . '/var/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}
$stamp = date('Ymd-His');
$sqlFile = $logDir . '/awb2bcustomer-migration-' . $stamp . '.sql';
$csvFile = $logDir . '/awb2bcustomer-migration-' . $stamp . '.csv';

$sqlDump = '-- awb2bcustomer: migration registrationfields -> native customer.company / customer.siret' . PHP_EOL
    . '-- Run on ' . date('c') . PHP_EOL
    . '-- ' . count($updates) . ' accounts updated. ROLLBACK: restores the previous native values.' . PHP_EOL;

$csv = fopen($csvFile, 'w');
fputcsv($csv, ['id_customer', 'email', 'company_before', 'company_after', 'siret_before', 'siret_after', 'note'], ';');

$done = 0;
foreach ($updates as $idCustomer => $update) {
    $row = $update['row'];
    $set = [];
    $rollback = [];
    foreach (['company', 'siret'] as $field) {
        if (isset($update[$field])) {
            $set[] = '`' . $field . '` = "' . pSQL($update[$field]) . '"';
            $rollback[] = '`' . $field . '` = "' . pSQL((string) $row[$field]) . '"';
        }
    }
    $ok = $db->execute('UPDATE `' . $prefix . 'customer` SET ' . implode(', ', $set) . ', date_upd = NOW() WHERE id_customer = ' . (int) $idCustomer);
    if (!$ok) {
        fwrite(STDERR, 'UPDATE failed for customer #' . $idCustomer . ': ' . $db->getMsgError() . PHP_EOL);
        continue;
    }
    ++$done;
    $sqlDump .= 'UPDATE `' . $prefix . 'customer` SET ' . implode(', ', $rollback) . ' WHERE id_customer = ' . (int) $idCustomer . ';' . PHP_EOL;
    fputcsv($csv, [
        $idCustomer,
        $row['email'],
        $row['company'],
        $update['company'] ?? $row['company'],
        $row['siret'],
        $update['siret'] ?? $row['siret'],
        '',
    ], ';');
}
// --- Too long values: private note -----------------------------------------
$notesDone = 0;
foreach ($tooLong as [$row, $field, $value, $raw]) {
    if ($noteAlreadyThere($row, $field)) {
        fputcsv($csv, [$row['id_customer'], $row['email'], $row['company'], $row['company'], $row['siret'], $row['siret'], 'too long ' . $field . ' already in the private note: ' . $raw], ';');
        continue;
    }
    $previous = (string) $row['note'];
    $note = trim($previous) !== '' ? rtrim($previous) . "\n\n" . $noteText($field, $raw) : $noteText($field, $raw);
    $ok = $db->execute('UPDATE `' . $prefix . 'customer` SET `note` = "' . pSQL($note, true) . '", date_upd = NOW() WHERE id_customer = ' . (int) $row['id_customer']);
    if (!$ok) {
        fwrite(STDERR, 'UPDATE (note) failed for customer #' . $row['id_customer'] . ': ' . $db->getMsgError() . PHP_EOL);
        continue;
    }
    ++$notesDone;
    $row['note'] = $note; // in case the same customer has both fields too long
    $sqlDump .= 'UPDATE `' . $prefix . 'customer` SET `note` = "' . pSQL($previous, true) . '" WHERE id_customer = ' . (int) $row['id_customer'] . ';' . PHP_EOL;
    fputcsv($csv, [$row['id_customer'], $row['email'], $row['company'], $row['company'], $row['siret'], $row['siret'], 'too long ' . $field . ' written in the private note: ' . $raw], ';');
}
foreach ($conflicts as [$row, $field, $native, $value]) {
    fputcsv($csv, [$row['id_customer'], $row['email'], $row['company'], $row['company'], $row['siret'], $row['siret'], 'conflict ' . $field . ': custom=' . $value], ';');
}
fclose($csv);
file_put_contents($sqlFile, $sqlDump);

printf("Done: %d account(s) updated, %d private note(s) written.\n", $done, $notesDone);
printf("Audit + rollback SQL : %s\n", $sqlFile);
printf("Audit CSV            : %s\n\n", $csvFile);

echo "The registrationfields tables are left untouched; they go away with the module." . PHP_EOL;
