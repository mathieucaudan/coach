<?php
require_once __DIR__.'/BankingProvider.php'; require_once dirname(__DIR__).'/gocardless/GoCardlessClient.php';
class GoCardlessProvider implements BankingProvider {
 private $client; public function __construct(GoCardlessClient $c){$this->client=$c;}
 public function institutions():array{return $this->client->getInstitutions('FR');}
 public function createLink(string $i,string $r,string $ref):array{$a=$this->client->createAgreement($i);$q=$this->client->createRequisition($r,$i,$ref,$a['id']??null);$q['agreement']=$a;return $q;}
 public function requisition(string $id):array{return $this->client->getRequisition($id);}
 public function revoke(string $id):void{$this->client->deleteRequisition($id);}
 public function details(string $id):array{return $this->client->getAccountDetails($id);}
 public function balances(string $id):array{return $this->client->getAccountBalances($id);}
 public function transactions(string $id,?string $from=null,?string $to=null):array{return $this->client->getAccountTransactions($id,$from,$to);}
}
