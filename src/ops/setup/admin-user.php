<?php
/**
 * Make the back office account match ADMIN_MAIL / ADMIN_PASSWD.
 *
 * The installer creates one employee, once, from whatever those variables held
 * at install time. Nothing afterwards ever reconciles them, so correcting the
 * stack environment later changes nothing and the login just says "The employee
 * does not exist, or the password provided is incorrect" - which reads like a
 * typo rather than a shop that was installed before the values were right.
 *
 * Idempotent. Creates the employee if missing, updates the password if it does
 * not match, and says which it did. Reads the password from the environment so
 * it is never an argument, never in shell history, and never in a log.
 */
require_once '/var/www/html/config/config.inc.php';

use PrestaShop\PrestaShop\Core\Crypto\Hashing;

$email = trim((string) getenv('ADMIN_MAIL'));
$plain = (string) getenv('ADMIN_PASSWD');

if ($email === '' || $plain === '') {
    fwrite(STDERR, "   ! ADMIN_MAIL and ADMIN_PASSWD must both be set\n");
    exit(1);
}
if (!Validate::isEmail($email)) {
    fwrite(STDERR, "   ! ADMIN_MAIL is not a valid email: $email\n");
    exit(1);
}
if (strlen($plain) < 8) {
    fwrite(STDERR, "   ! ADMIN_PASSWD is " . strlen($plain) . " characters; PrestaShop requires 8\n");
    exit(1);
}

$crypto = new Hashing();

$id = (int) Db::getInstance()->getValue(
    'SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee WHERE email = "' . pSQL($email) . '"'
);

if ($id) {
    $employee = new Employee($id);
    if ($crypto->checkHash($plain, $employee->passwd)) {
        echo "   . $email already matches\n";
        exit(0);
    }
    $employee->passwd = $crypto->hash($plain);
    $employee->update();
    echo "   + password reset for $email\n";
    exit(0);
}

// No employee with that address. Rather than add a second account beside the
// installer's, rename the existing SuperAdmin if there is exactly one - that is
// the account the installer made, under the old address.
$existing = Db::getInstance()->executeS(
    'SELECT id_employee, email FROM ' . _DB_PREFIX_ . 'employee WHERE id_profile = ' . (int) _PS_ADMIN_PROFILE_
);

if (count($existing) === 1) {
    $employee = new Employee((int) $existing[0]['id_employee']);
    $was = $employee->email;
    $employee->email = $email;
    $employee->passwd = $crypto->hash($plain);
    $employee->update();
    echo "   + $was -> $email, password set\n";
    exit(0);
}

$employee = new Employee();
$employee->firstname = 'Karlo';
$employee->lastname = 'Krakan';
$employee->email = $email;
$employee->passwd = $crypto->hash($plain);
$employee->id_profile = (int) _PS_ADMIN_PROFILE_;
$employee->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
$employee->active = 1;

if (!$employee->add()) {
    fwrite(STDERR, "   ! could not create $email\n");
    exit(1);
}
echo "   + created $email\n";
