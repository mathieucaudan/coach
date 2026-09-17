<?php
require_once __DIR__.'/BankingProvider.php';
class MockBankingProvider implements BankingProvider {
 public function institutions():array{return [['id'=>'MOCK_CA_ALPES','name'=>'Crédit Agricole des Alpes (démo)','logo'=>'']];}
 public function createLink(string $i,string $r,string $ref):array{return ['id'=>'mock-req-'.substr(hash('sha256',$ref),0,12),'link'=>$r.'&mock_complete=1','status'=>'CR','agreement'=>['access_valid_for_days'=>90]];}
 public function requisition(string $id):array{return ['id'=>$id,'status'=>'LN','accounts'=>['mock-account-1','mock-account-2']];}
 public function revoke(string $id):void{}
 public function details(string $id):array{return ['account'=>['iban'=>$id==='mock-account-1'?'FR7612345678901234567890123':'FR7699999999999999999999999','currency'=>'EUR','name'=>$id==='mock-account-1'?'Compte courant association':'Compte épargne']];}
 public function balances(string $id):array{return ['balances'=>[['balanceAmount'=>['amount'=>$id==='mock-account-1'?'12542.83':'2800.00','currency'=>'EUR'],'balanceType'=>'closingBooked'],['balanceAmount'=>['amount'=>$id==='mock-account-1'?'12390.83':'2800.00','currency'=>'EUR'],'balanceType'=>'interimAvailable']]];}
 public function transactions(string $id,?string $from=null,?string $to=null):array{$today=date('Y-m-d');return ['transactions'=>['booked'=>[
  ['transactionId'=>'mock-helloasso','bookingDate'=>$today,'valueDate'=>$today,'transactionAmount'=>['amount'=>'725.00','currency'=>'EUR'],'remittanceInformationUnstructured'=>'HELLOASSO COTISATIONS','debtorName'=>'HELLOASSO'],
  ['transactionId'=>'mock-decathlon','bookingDate'=>date('Y-m-d',strtotime('-1 day')),'valueDate'=>date('Y-m-d',strtotime('-1 day')),'transactionAmount'=>['amount'=>'-129.99','currency'=>'EUR'],'remittanceInformationUnstructured'=>'CB DECATHLON','creditorName'=>'DECATHLON']
 ],'pending'=>[['transactionId'=>'mock-pending','valueDate'=>$today,'transactionAmount'=>['amount'=>'-48.90','currency'=>'EUR'],'remittanceInformationUnstructured'=>'CARREFOUR MARKET','creditorName'=>'CARREFOUR']]]];}
}
