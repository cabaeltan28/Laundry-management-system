<?php
/**
 * LaundryFlow configuration
 * Edit these values to match your XAMPP / hosting setup.
 */

// ---- Database (XAMPP default: user root, empty password) ----
define('DB_HOST', getenv('DB_HOST') !== false ? getenv('DB_HOST') : 'localhost');
define('DB_PORT', getenv('DB_PORT') !== false ? getenv('DB_PORT') : '3306');
define('DB_NAME', getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'laundryflow');
define('DB_USER', getenv('DB_USER') !== false ? getenv('DB_USER') : 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

// ---- App ----
define('APP_TIMEZONE', 'Asia/Manila');
define('APP_VERSION', '3.0');

// Folder name inside htdocs. Leave '' to auto-detect.
// Example: if you open http://localhost/laundryflow/ then BASE_URL is '/laundryflow'
define('BASE_URL', '');

// Address that CUSTOMERS' PHONES use to open the system (for the QR code on
// the claim stub). Phones cannot open "localhost". Examples:
//   same Wi-Fi as the shop computer: 'http://192.168.1.10/laundryflow'
//   online hosting:                  'https://mylaundry.com'
// Leave '' to use the address shown in the browser.
define('PUBLIC_URL', getenv('PUBLIC_URL') !== false
    ? getenv('PUBLIC_URL')
    : 'https://relax-cradle-villa-liver.trycloudflare.com/LaundryFlow-final');

// Show PHP errors while developing. Set to false when deployed.
define('APP_DEBUG', true);

// ---- SMS (optional) ----
// Leave disabled for school demo. Messages are still saved in the
// notifications table with channel 'sms_log' so you can show them.
// To send real SMS in the Philippines you can use Semaphore (semaphore.co).
define('SMS_ENABLED', false);
define('SMS_API_KEY', '');
define('SMS_SENDER_NAME', '');

// ---- AI (optional) ----
// The system works WITHOUT AI: the order reader, business assistant and
// customer help all have a built-in offline mode. Add a Claude API key
// (console.anthropic.com) to make them smarter.
define('AI_ENABLED', false);
define('AI_API_KEY', '');                 // e.g. 'sk-ant-...'
define('AI_MODEL', 'claude-haiku-5-5');   // fast and low-cost model
define('AI_TIMEOUT', 20);                 // seconds
// Windows/XAMPP: if AI gives an "SSL certificate" error, download cacert.pem
// from https://curl.se/ca/cacert.pem and put its full path here:
define('AI_CA_BUNDLE', '');               // e.g. 'C:\\xampp\\php\\extras\\ssl\\cacert.pem'
