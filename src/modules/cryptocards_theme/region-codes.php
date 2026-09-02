<?php
/**
 * Region short codes, mirrored from ops/lib/region.php.
 *
 * The module cannot require that file: ops/ is a provisioning bind mount that
 * exists in the containers but not in a deployed module directory. This is the
 * one constant the storefront needs from it, so it is duplicated deliberately
 * and kept small enough to be obviously in sync.
 */
declare(strict_types=1);

if (!defined('REGION_CODE')) {
    define('REGION_CODE', [
        'Western' => '',
        'Japanese' => 'JP',
        'Chinese' => 'CN',
    ]);
}
