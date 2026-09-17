<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/functions.php';
if (in_array('--mock',$argv,true)) {
    if ((defined('APP_ENV') ? APP_ENV : 'production') === 'production') { fwrite(STDERR,"Mock interdit en production.\n"); exit(2); }
    if (!defined('BANKING_MOCK_MODE') || !BANKING_MOCK_MODE) { fwrite(STDERR,"Activez BANKING_MOCK_MODE dans config.php.\n"); exit(2); }
}
try { $result=banking_service()->syncAllBankAccounts(); echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL; exit($result['failed']?1:0); }
catch(Throwable $e){ error_log('[banking] cron_failed '.get_class($e)); fwrite(STDERR,"Synchronisation bancaire impossible.\n"); exit(1); }
