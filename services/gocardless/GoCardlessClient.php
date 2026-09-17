<?php
require_once __DIR__ . '/GoCardlessException.php';

class GoCardlessClient {
    private $pdo; private $baseUrl; private $secretId; private $secretKey; private $transport;
    public function __construct(PDO $pdo, string $secretId, string $secretKey, ?callable $transport = null, string $baseUrl = 'https://bankaccountdata.gocardless.com/api/v2') {
        $this->pdo=$pdo; $this->secretId=$secretId; $this->secretKey=$secretKey; $this->transport=$transport; $this->baseUrl=rtrim($baseUrl,'/');
    }
    public function getAccessToken(): string {
        $row=$this->pdo->query("SELECT * FROM bank_provider_tokens WHERE provider='gocardless'")->fetch();
        if ($row && !empty($row['access_token']) && strtotime((string)$row['access_expires_at']) > time()+60) return $row['access_token'];
        if ($row && !empty($row['refresh_token']) && strtotime((string)$row['refresh_expires_at']) > time()+60) {
            try { return $this->refreshAccessToken($row['refresh_token'], $row['refresh_expires_at']??null); } catch (GoCardlessException $e) { /* create anew */ }
        }
        if ($this->secretId==='' || $this->secretKey==='') throw new GoCardlessException('Configuration bancaire serveur incomplète.');
        $data=$this->request('POST','/token/new/',['secret_id'=>$this->secretId,'secret_key'=>$this->secretKey],false);
        return $this->storeTokens($data);
    }
    public function refreshAccessToken(string $refreshToken = '', ?string $existingRefreshExpiry=null): string {
        if ($refreshToken==='') { $row=$this->pdo->query("SELECT refresh_token,refresh_expires_at FROM bank_provider_tokens WHERE provider='gocardless'")->fetch(); $refreshToken=(string)($row['refresh_token']??''); $existingRefreshExpiry=$row['refresh_expires_at']??null; }
        if ($refreshToken==='') throw new GoCardlessException('Jeton de renouvellement absent.');
        return $this->storeTokens($this->request('POST','/token/refresh/',['refresh'=>$refreshToken],false),$refreshToken,$existingRefreshExpiry);
    }
    private function storeTokens(array $data, string $existingRefresh='', ?string $existingRefreshExpiry=null): string {
        $access=(string)($data['access']??''); if ($access==='') throw new GoCardlessException('Réponse de jeton invalide.');
        $refresh=(string)($data['refresh']??$existingRefresh); $ae=date('Y-m-d H:i:s',time()+(int)($data['access_expires']??86400));
        $re=$refresh!=='' ? (isset($data['refresh_expires'])?date('Y-m-d H:i:s',time()+(int)$data['refresh_expires']):($existingRefreshExpiry?:date('Y-m-d H:i:s',time()+2592000))) : null;
        $s=$this->pdo->prepare("INSERT INTO bank_provider_tokens(provider,access_token,refresh_token,access_expires_at,refresh_expires_at) VALUES('gocardless',?,?,?,?) ON DUPLICATE KEY UPDATE access_token=VALUES(access_token),refresh_token=VALUES(refresh_token),access_expires_at=VALUES(access_expires_at),refresh_expires_at=VALUES(refresh_expires_at)");
        $s->execute([$access,$refresh,$ae,$re]); return $access;
    }
    public function getInstitutions(string $country='FR'): array { return $this->request('GET','/institutions/?country='.rawurlencode($country)); }
    public function createAgreement(string $institutionId, int $days=90): array { return $this->request('POST','/agreements/enduser/',['institution_id'=>$institutionId,'max_historical_days'=>90,'access_valid_for_days'=>$days,'access_scope'=>['balances','details','transactions']]); }
    public function createRequisition(string $redirect, string $institutionId, string $reference, ?string $agreement=null): array {
        $body=['redirect'=>$redirect,'institution_id'=>$institutionId,'reference'=>$reference,'user_language'=>'FR']; if($agreement)$body['agreement']=$agreement;
        return $this->request('POST','/requisitions/',$body);
    }
    public function getRequisition(string $id): array { return $this->request('GET','/requisitions/'.rawurlencode($id).'/'); }
    public function deleteRequisition(string $id): void { $this->request('DELETE','/requisitions/'.rawurlencode($id).'/'); }
    public function getAccountDetails(string $id): array { return $this->request('GET','/accounts/'.rawurlencode($id).'/details/'); }
    public function getAccountBalances(string $id): array { return $this->request('GET','/accounts/'.rawurlencode($id).'/balances/'); }
    public function getAccountTransactions(string $id, ?string $dateFrom=null, ?string $dateTo=null): array {
        $query=[]; if($dateFrom)$query['date_from']=$dateFrom; if($dateTo)$query['date_to']=$dateTo;
        return $this->request('GET','/accounts/'.rawurlencode($id).'/transactions/'.($query?'?'.http_build_query($query):''));
    }
    private function request(string $method,string $path,?array $body=null,bool $auth=true,bool $retriedAuth=false): array {
        $headers=['Accept: application/json','Content-Type: application/json']; if($auth)$headers[]='Authorization: Bearer '.$this->getAccessToken();
        $call=function() use($method,$path,$body,$headers){
            if($this->transport)return call_user_func($this->transport,$method,$this->baseUrl.$path,$body,$headers);
            if (!function_exists('curl_init')) throw new GoCardlessException('Extension PHP cURL manquante.');
            $ch=curl_init($this->baseUrl.$path); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8]);
            if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body)); $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $error=curl_error($ch); curl_close($ch);
            return ['status'=>$status,'body'=>$raw===false?'':$raw,'error'=>$error];
        };
        $tries=0; do { $res=$call(); $status=(int)($res['status']??0); if(!in_array($status,[429,500,502,503,504],true))break; usleep((int)(200000*pow(2,$tries))); } while(++$tries<3);
        if($status===401&&$auth&&!$retriedAuth){$this->pdo->exec("UPDATE bank_provider_tokens SET access_token=NULL,access_expires_at=NULL WHERE provider='gocardless'");return $this->request($method,$path,$body,true,true);}
        if($status<200||$status>=300){$message=$status===429?'Service bancaire temporairement limité.':($status>=500||$status===0?'Service bancaire temporairement indisponible.':($status===401||$status===403?'Autorisation bancaire à renouveler.':'Opération bancaire impossible.'));throw new GoCardlessException($message,$status);}
        if($status===204)return []; $decoded=json_decode((string)($res['body']??''),true); if(!is_array($decoded))throw new GoCardlessException('Réponse bancaire invalide.',$status); return $decoded;
    }
}
