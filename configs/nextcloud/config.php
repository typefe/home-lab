<?php
$CONFIG = array (
  'instanceid' => 'oc1h20xthd9t',
  'passwordsalt' => 'your-generated-passwordsalt-here',
  'secret' => 'your-generated-secret-here',
  'server_name' => 'cloud.typefe.pro',
  'trusted_domains' => 
  array (
    0 => 'localhost',
    1 => '192.168.20.55',
    2 => 'cloud.typefe.pro',
  ),
  'htaccess.RewriteBase' => '/',
  'overwrite.cli.url' => 'https://cloud.typefe.pro',
  'overwriteprotocol' => 'https',
  'overwritecondaddr' => '^(192\\.168\\.|127\\.0\\.0\\.1)',
  'memcache.local' => '\\OC\\Memcache\\APCu',
  'memcache.locking' => '\\OC\\Memcache\\Redis',
  'redis' => 
  array (
    'host' => 'localhost',
    'port' => 6379,
  ),
  'datadirectory' => '/mnt/DATA/nextcloud',
  'dbtype' => 'pgsql',
  'version' => '33.0.0.16',
  'dbname' => 'nextcloud',
  'dbhost' => '192.168.20.20',
  'dbport' => '',
  'dbtableprefix' => 'oc_',
  'dbuser' => 'nextcloud_user',
  'dbpassword' => 'your-db-password-here',
  'installed' => true,
  'maintenance' => false,
  'app_install_overwrite' => 
  array (
  ),
  'loglevel' => 2,
);
