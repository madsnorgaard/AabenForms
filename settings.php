<?php

$databases = [];
$databases['default']['default'] = array(
  'database' => getenv('DB_NAME'),
  'username' => getenv('DB_USER'),
  'password' => getenv('DB_PASS'),
  'prefix' => '',
  'host' => getenv('DB_HOST'),
  'port' => getenv('DB_PORT' ?: '3306'),
  'namespace' => 'Drupal\Core\Database\Driver\mysql',
  'driver' => 'mysql',
  // Drupal recommends READ COMMITTED. MariaDB defaults to REPEATABLE-READ
  // which the status report flags. Setting it per-session is cheaper than
  // restarting the DB server with a config change.
  'init_commands' => [
    'isolation_level' => "SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED",
  ],
);

$settings['hash_salt'] = getenv('HASH_SALT');
$settings['update_free_access'] = false;
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';

// Trusted hosts: a comma-separated list of regular expressions in
// TRUSTED_HOST_PATTERNS (the name docker-compose.yml and .env.example use;
// TRUSTED_HOSTS is read for older deployments). An empty value used to end
// up as the pattern '{}i', which matches every Host header and silently
// turned the check off; now nothing is set and Drupal's status report says so.
$trusted_hosts = getenv('TRUSTED_HOST_PATTERNS') ?: getenv('TRUSTED_HOSTS') ?: '';
foreach (array_filter(array_map('trim', explode(',', $trusted_hosts))) as $trusted_host_pattern) {
  $settings['trusted_host_patterns'][] = $trusted_host_pattern;
}

$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];
$settings['entity_update_batch_size'] = 100;
$settings['entity_update_backup'] = true;
$settings['config_sync_directory'] = '../config/sync';

// Private file system. Path is OUTSIDE the docroot per Drupal security
// guidance (DRUPAL-PSA-2016-003). Webform stores submissions and
// attachments here when configured. The directory is bind-mounted from
// ./data/private on VPS2 (volume in docker-compose.yml) so contents
// persist across container rebuilds.
$settings['file_private_path'] = '/opt/drupal/private';

// Redis cache - enable after `drush en redis`
// if (extension_loaded('redis')) {
//     $settings['redis.connection']['interface'] = 'PhpRedis';
//     $settings['redis.connection']['host'] = getenv('REDIS_HOST');
//     $settings['redis.connection']['port'] = '6379';
//     $settings['cache']['default'] = 'cache.backend.redis';
//     $settings['cache_prefix'] = 'aabenforms_redis_';
// }

if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
    include $app_root . '/' . $site_path . '/settings.local.php';
}

# Behind Traefik: honour X-Forwarded-* only from the proxies named in
# REVERSE_PROXY_ADDRESSES (comma-separated IPs or CIDR ranges, e.g. the
# Traefik container address or the docker network). Without the variable the
# direct caller is trusted, which lets any container on the shared network
# spoof the client IP that rate limits and the audit log use.
$settings['reverse_proxy'] = true;
$reverse_proxies = array_filter(array_map('trim', explode(',', getenv('REVERSE_PROXY_ADDRESSES') ?: '')));
$settings['reverse_proxy_addresses'] = $reverse_proxies ?: array(@$_SERVER['REMOTE_ADDR']);

# MitID OIDC: production overrides for the public mock IdP at
# https://auth.aabenforms.dk. The browser hits the public hostname for the
# /auth redirect and Drupal does the server-side token exchange against the
# internal http://keycloak:8080. Issuer must match what's claimed in the
# id_token, so it has to be the public URL.
# Local DDEV leaves these env vars unset and falls back to the YAML config.
if ($mitid_authz = getenv('MITID_AUTH_ENDPOINT')) {
  $config['aabenforms_mitid.settings']['authorization_endpoint'] = $mitid_authz;
}
if ($mitid_token = getenv('MITID_TOKEN_ENDPOINT')) {
  $config['aabenforms_mitid.settings']['token_endpoint'] = $mitid_token;
}
if ($mitid_userinfo = getenv('MITID_USERINFO_ENDPOINT')) {
  $config['aabenforms_mitid.settings']['userinfo_endpoint'] = $mitid_userinfo;
}
if ($mitid_issuer = getenv('MITID_ISSUER')) {
  $config['aabenforms_mitid.settings']['issuer'] = $mitid_issuer;
}
if ($mitid_redirect = getenv('MITID_REDIRECT_URI')) {
  $config['aabenforms_mitid.settings']['redirect_uri'] = $mitid_redirect;
}
if ($mitid_client_secret = getenv('MITID_CLIENT_SECRET')) {
  $config['aabenforms_mitid.settings']['client_secret'] = $mitid_client_secret;
}
if (getenv('AABENFORMS_PROD_MITID') === 'true') {
  $config['aabenforms_mitid.settings']['production'] = TRUE;
}

# SMTP configuration from environment
if (getenv('SMTP_PASSWORD')) {
  $config['smtp.settings']['smtp_on'] = TRUE;
  $config['smtp.settings']['smtp_host'] = getenv('SMTP_HOST') ?: 'mail.madsnorgaard.net';
  $config['smtp.settings']['smtp_port'] = getenv('SMTP_PORT') ?: '587';
  $config['smtp.settings']['smtp_protocol'] = getenv('SMTP_PROTOCOL') ?: 'tls';
  $config['smtp.settings']['smtp_username'] = getenv('SMTP_USER') ?: 'mads@madsnorgaard.net';
  $config['smtp.settings']['smtp_password'] = getenv('SMTP_PASSWORD');
  $config['smtp.settings']['smtp_from'] = getenv('SMTP_FROM') ?: 'mads@madsnorgaard.net';
  $config['smtp.settings']['smtp_fromname'] = 'ÅbenForms';
  $config['smtp.settings']['smtp_allowhtml'] = TRUE;
  $config['system.mail']['interface']['default'] = 'SMTPMailSystem';
}
