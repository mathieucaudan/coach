<?php
interface BankingProvider {
    public function institutions(): array;
    public function createLink(string $institutionId,string $redirect,string $reference): array;
    public function requisition(string $id): array;
    public function revoke(string $id): void;
    public function details(string $accountId): array;
    public function balances(string $accountId): array;
    public function transactions(string $accountId, ?string $dateFrom = null, ?string $dateTo = null): array;
}
