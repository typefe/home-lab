<?php
$CONFIG = array (
  'instanceid' => '',
  'passwordsalt' => '',
  'secret' => '',
  'trusted_domains' => 
  array (
    0 => 'localhost',
    1 => '192.168.20.55',
    2 => 'cloud.typefe.pro',
  ),
  'overwrite.cli.url' => 'https://cloud.typefe.pro',
  'overwriteprotocol' => 'https',
  'overwritecondaddr' => '^(192\.168\.|127\.0\.0\.1)',
  'memcache.local' => '\\OC\\Memcache\\Redis',
  'memcache.locking' => '\\OC\\Memcache\\Redis',
  'redis' => [
    'host' => 'localhost',
    'port' => 6379,
  ],
  'htaccess.RewriteBase' => '/', 
  'datadirectory' => '/mnt/DATA/nextcloud',
  'dbtype' => 'pgsql',
  'version' => '31.0.9.1',
  'dbname' => 'nextcloud',
  'dbhost' => '192.168.20.20',
  'dbport' => '',
  'dbtableprefix' => 'oc_',
  'dbuser' => '',
  'dbpassword' => '',
  'installed' => true,
);