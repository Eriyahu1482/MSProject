<?php
declare(strict_types=1);

final class MoySklad
{
    /** @var string */
    private $baseUrl = 'https://api.moysklad.ru/api/remap/1.2';

    /** @var string */
    private $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json;charset=utf-8',
                'Content-Type: application/json',
            ],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Ошибка соединения с МойСклад: ' . $err);
        }
        curl_close($ch);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        if ($code >= 400) {
            $msg = $data['errors'][0]['error'] ?? ('HTTP ' . $code);
            throw new RuntimeException('МойСклад: ' . $msg);
        }
        return $data;
    }

    private function meta(array $row): array
    {
        return ['meta' => $row['meta']];
    }

    public function findOrganization(string $name): array
    {
        $r = $this->request('GET', '/entity/organization', ['filter' => 'name=' . $name, 'limit' => 1]);
        if (empty($r['rows'])) {
            throw new RuntimeException("Организация «{$name}» не найдена в МойСклад.");
        }
        return $this->meta($r['rows'][0]);
    }

    public function findCounterparty(string $name): ?array
    {
        $r = $this->request('GET', '/entity/counterparty', ['filter' => 'name=' . $name, 'limit' => 1]);
        return empty($r['rows']) ? null : $this->meta($r['rows'][0]);
    }

    public function findOrCreateCounterparty(string $name): array
    {
        $found = $this->findCounterparty($name);
        if ($found !== null) {
            return $found;
        }
        $r = $this->request('POST', '/entity/counterparty', [], ['name' => $name]);
        return $this->meta($r);
    }

    public function getOrganizations(): array
    {
        $r = $this->request('GET', '/entity/organization', ['limit' => 1000]);
        return $r['rows'] ?? [];
    }

    public function getCounterparties(): array
    {
        $r = $this->request('GET', '/entity/counterparty', ['limit' => 1000]);
        return $r['rows'] ?? [];
    }

    public function getStates(): array
    {
        $r = $this->request('GET', '/entity/customerorder/metadata');
        return $r['states'] ?? [];
    }

    public function createOrder(string $number, array $orgMeta, array $agentMeta, ?array $stateMeta): string
    {
        $body = [
            'name'         => $number,
            'organization' => $orgMeta,
            'agent'        => $agentMeta,
        ];
        if ($stateMeta !== null) {
            $body['state'] = $stateMeta;
        }
        return $this->request('POST', '/entity/customerorder', [], $body)['id'];
    }

    /** Возвращает актуальный статус заказа из МС или null */
    /** Возвращает актуальный статус заказа из МС или null */
    public function getOrderStateName(string $msId): ?string
    {
        // Передаем параметр expand=state, чтобы МойСклад вернул не только ссылку, но и название статуса
        $order = $this->request('GET', '/entity/customerorder/' . $msId, ['expand' => 'state']);

        // Если статус есть, возвращаем его название
        if (!empty($order['state']['name'])) {
            return (string)$order['state']['name'];
        }

        // Страховка: если expand не сработал, ищем статус по ссылке в списке всех статусов
        if (!empty($order['state']['meta']['href'])) {
            $states = $this->getStates();
            foreach ($states as $st) {
                if (isset($st['meta']['href']) && $st['meta']['href'] === $order['state']['meta']['href']) {
                    return (string)$st['name'];
                }
            }
        }

        return null;
    }

    /** Обновляет статус в МС */
    public function updateOrderState(string $msId, string $stateName): array
    {
        $states = $this->getStates();

        $stateMeta = null;
        foreach ($states as $state) {
            if (trim((string)$state['name']) === trim($stateName)) {
                $stateMeta = ['meta' => $state['meta']];
                break;
            }
        }

        if ($stateMeta === null) {
            $foundNames = array_column($states, 'name');
            throw new RuntimeException(
                "Статус «{$stateName}» не найден. Доступные: " . implode(', ', $foundNames)
            );
        }

        // Просто делаем PUT. Если МС вернул 200, значит всё ок.
        return $this->request('PUT', '/entity/customerorder/' . $msId, [], ['state' => $stateMeta]);
    }
}