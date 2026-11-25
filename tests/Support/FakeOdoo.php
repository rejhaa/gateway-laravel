<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * An Odoo held in memory behind Http::fake(). It answers search_read on id
 * and on equal fields, read by ids, and write, which changes the records.
 * Tests change $records directly to play someone editing Odoo.
 *
 * line() builds the records one purchase order line needs. They are the
 * confirmed order P00104, its supplier, its product, and the line itself, 120 ordered and
 * planned for 2026-11-30 at 10:00 UTC, which is the requested date too.
 */
final class FakeOdoo
{
    public const URL = 'http://fake-odoo.test';

    public const LINE = 36;

    public const ORDER = 7;

    public const PRODUCT = 11;

    public const SUPPLIER = 3;

    public const BILL = 51;

    /** @var array<string, array<int, array<string, mixed>>> model to id to fields */
    public array $records = [];

    /** @var list<array{model: string, id: int, values: array<string, mixed>, audit: list<string>}> each write, with the audit events recorded before it */
    public array $writes = [];

    /** @var (Closure(Request): PromiseInterface)|null */
    private ?Closure $writeAnswer = null;

    public static function start(): self
    {
        $keysFile = sys_get_temp_dir().'/gateway-fake-odoo-keys.json';
        file_put_contents($keysFile, json_encode(['riku' => ['api_key' => 'fake-key']]));

        config([
            'gateway.odoo.url' => self::URL,
            'gateway.odoo.keys_file' => $keysFile,
            'gateway.user.odoo_login' => 'riku',
        ]);

        $odoo = new self;
        Http::preventStrayRequests();
        Http::fake([self::URL.'/*' => fn (Request $request): PromiseInterface => $odoo->answer($request)]);

        return $odoo;
    }

    /**
     * @param  array<string, mixed>  $line  fields that differ from the standard line
     * @param  array<string, mixed>  $order  fields that differ from the standard order
     */
    public function line(array $line = [], array $order = []): self
    {
        $this->records['purchase.order'][self::ORDER] = [
            'name' => 'P00104',
            'partner_ref' => 'TYF-OC-26-0918',
            'partner_id' => [self::SUPPLIER, 'Tai-Yuan Forge & Shaft'],
            'state' => 'purchase',
            ...$order,
        ];

        $this->records['res.partner'][self::SUPPLIER] = [
            'name' => 'Tai-Yuan Forge & Shaft',
            'commercial_partner_id' => [self::SUPPLIER, 'Tai-Yuan Forge & Shaft'],
            'type' => 'contact',
            'email_normalized' => 'sales@taiyuan.example',
        ];

        $this->records['product.product'][self::PRODUCT] = [
            'default_code' => 'VG-HEAD-V1',
            'name' => 'V1 forged iron head',
            'seller_ids' => [],
        ];

        $this->records['purchase.order.line'][self::LINE] = [
            'order_id' => [self::ORDER, 'P00104'],
            'product_id' => [self::PRODUCT, '[VG-HEAD-V1] V1 forged iron head'],
            'product_qty' => 120.0,
            'date_planned' => '2026-11-30 10:00:00',
            'date_promised' => '2026-11-30 10:00:00',
            'x_confirmed_qty' => false,
            'x_confirmed_date' => false,
            'move_dest_ids' => [],
            ...$line,
        ];

        return $this;
    }

    /** A manufacturing order that consumes the line's goods through one stock move. */
    public function waitingOrder(int $moveId, int $productionId, string $name, string $start, float $need): self
    {
        $this->records['stock.move'][$moveId] = [
            'raw_material_production_id' => [$productionId, $name],
            'product_uom_qty' => $need,
        ];
        $this->records['mrp.production'][$productionId] = ['name' => $name, 'date_start' => $start];
        $this->records['purchase.order.line'][self::LINE]['move_dest_ids'][] = $moveId;

        return $this;
    }

    /** A posted vendor bill with no dispute. */
    public function bill(): self
    {
        $this->records['account.move'][self::BILL] = [
            'name' => 'BILL/2026/11/0007',
            'ref' => 'TYF-INV-2211',
            'state' => 'posted',
            'partner_id' => [self::SUPPLIER, 'Tai-Yuan Forge & Shaft'],
            'amount_untaxed' => 4800.0,
            'currency_id' => [1, 'EUR'],
            'x_dispute' => false,
            'x_dispute_note' => false,
        ];

        return $this;
    }

    /** Every write is refused with this status and message, as Odoo answers an error. */
    public function refuseWrites(int $status, string $message): self
    {
        $this->writeAnswer = fn (): PromiseInterface => Http::response(['name' => 'odoo.exceptions.AccessError', 'message' => $message, 'debug' => ''], $status);

        return $this;
    }

    /** Writes succeed again after refuseWrites() or timeOutWrites(). */
    public function acceptWrites(): self
    {
        $this->writeAnswer = null;

        return $this;
    }

    /** Every write times out. When $committed, Odoo took it before the answer was lost. */
    public function timeOutWrites(bool $committed): self
    {
        $this->writeAnswer = function (Request $request) use ($committed): PromiseInterface {
            if ($committed) {
                $this->commit($request);
            }

            return Create::rejectionFor(new NetworkTimeoutException('cURL error 28: Operation timed out', $request->toPsrRequest()));
        };

        return $this;
    }

    private function answer(Request $request): PromiseInterface
    {
        [$model, $method] = array_slice(explode('/', parse_url($request->url(), PHP_URL_PATH)), -2);
        $data = $request->data();

        return match ($method) {
            'search_read' => Http::response($this->search($model, $data['domain'], $data['fields'], $data['limit'])),
            'read' => $this->read($model, $data['ids'], $data['fields']),
            'write' => $this->write($request),
        };
    }

    /**
     * @param  list<array{0: string, 1: string, 2: mixed}>  $domain
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function search(string $model, array $domain, array $fields, int $limit): array
    {
        $found = [];

        foreach ($this->records[$model] ?? [] as $id => $record) {
            $record['id'] = $id;
            $matches = array_all($domain, function (array $term) use ($record): bool {
                $value = $record[$term[0]] ?? null;

                return $term[1] === '=' && (is_array($value) ? $value[0] : $value) === $term[2];
            });

            if ($matches) {
                $found[] = $this->only($id, $record, $fields);
            }
        }

        return array_slice($found, 0, $limit);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $fields
     */
    private function read(string $model, array $ids, array $fields): PromiseInterface
    {
        $records = [];

        foreach ($ids as $id) {
            if (! isset($this->records[$model][$id])) {
                return Http::response(['name' => 'odoo.exceptions.MissingError', 'message' => 'Record does not exist or has been deleted.', 'debug' => ''], 404);
            }

            $records[] = $this->only($id, $this->records[$model][$id], $fields);
        }

        return Http::response($records);
    }

    private function write(Request $request): PromiseInterface
    {
        if ($this->writeAnswer !== null) {
            return ($this->writeAnswer)($request);
        }

        $this->commit($request);

        return Http::response('true');
    }

    private function commit(Request $request): void
    {
        [$model] = array_slice(explode('/', parse_url($request->url(), PHP_URL_PATH)), -2, 1);
        $data = $request->data();
        $id = $data['ids'][0];

        $this->writes[] = [
            'model' => $model,
            'id' => $id,
            'values' => $data['vals'],
            'audit' => DB::table('audit_log')->orderBy('id')->pluck('event')->all(),
        ];

        $this->records[$model][$id] = [...$this->records[$model][$id], ...$data['vals']];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function only(int $id, array $record, array $fields): array
    {
        $answer = ['id' => $id];

        foreach ($fields as $field) {
            $answer[$field] = $record[$field] ?? false;
        }

        return $answer;
    }
}
