<?php

namespace Tests\Feature;

use App\Actions\SaleSchemes\ValidateAppliedSaleOffersAction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Point 2 — customer spend scheme.
 *
 * Covers the server-side validator that the future sales save path will call.
 * Nothing here auto-adds a salesman scheme: the entry must submit the ULID
 * explicitly (Skip = submit nothing), and Laravel revalidates it.
 */
class ValidateAppliedSaleOffersTest extends TestCase
{
    use DatabaseTransactions;

    public function test_salesman_scheme_is_accepted_at_the_threshold(): void
    {
        $this->signInOwner('valid-1')->assertOk();
        $reward = $this->createProduct('Reward A');
        $scheme = $this->createScheme('Spend 5000', '5000.0000', $reward);

        $result = $this->validate('5000.0000', [], [$scheme]);

        $this->assertCount(0, $result['packaging_lines']);
        $this->assertCount(1, $result['scheme_lines']);

        $line = $result['scheme_lines'][0];
        $this->assertSame('free_scheme', $line['line_kind']);
        $this->assertFalse($line['auto_applied']);
        $this->assertSame($reward, $line['product']->ulid);
        $this->assertSame(0, bccomp((string) $line['qty'], '1', 6));
    }

    public function test_salesman_scheme_is_rejected_below_threshold(): void
    {
        $this->signInOwner('valid-2')->assertOk();
        $reward = $this->createProduct('Reward B');
        $scheme = $this->createScheme('Spend 5000', '5000.0000', $reward);

        $this->assertValidationFails(
            fn () => $this->validate('4999.9999', [], [$scheme]),
            'applied_scheme_ulids'
        );
    }

    public function test_inactive_scheme_is_rejected(): void
    {
        $this->signInOwner('valid-3')->assertOk();
        $reward = $this->createProduct('Reward C');
        $scheme = $this->createScheme('Spend 5000', '5000.0000', $reward);

        $this->patchJson('/api/sale-schemes/'.$scheme, ['is_active' => false])->assertOk();

        $this->assertValidationFails(
            fn () => $this->validate('9000.0000', [], [$scheme]),
            'applied_scheme_ulids'
        );
    }

    public function test_scheme_outside_its_date_window_is_rejected(): void
    {
        $this->signInOwner('valid-4')->assertOk();
        $reward = $this->createProduct('Reward D');

        $expired = $this->createScheme('Expired scheme', '1000.0000', $reward, [
            'starts_on' => '2020-01-01',
            'ends_on' => '2020-12-31',
        ]);
        $future = $this->createScheme('Future scheme', '1000.0000', $reward, [
            'starts_on' => '2099-01-01',
        ]);

        $this->assertValidationFails(
            fn () => $this->validate('5000.0000', [], [$expired]),
            'applied_scheme_ulids'
        );

        $this->assertValidationFails(
            fn () => $this->validate('5000.0000', [], [$future]),
            'applied_scheme_ulids'
        );
    }

    public function test_non_stackable_schemes_cannot_be_combined(): void
    {
        $this->signInOwner('valid-5')->assertOk();
        $reward = $this->createProduct('Reward E');

        $first = $this->createScheme('First', '1000.0000', $reward, ['is_stackable' => false]);
        $second = $this->createScheme('Second', '1000.0000', $reward, ['is_stackable' => false]);

        $this->assertValidationFails(
            fn () => $this->validate('5000.0000', [], [$first, $second]),
            'applied_scheme_ulids'
        );

        // One on its own is still allowed.
        $this->assertCount(1, $this->validate('5000.0000', [], [$first])['scheme_lines']);
    }

    public function test_scheme_from_another_tenant_is_not_eligible(): void
    {
        $this->signInOwner('valid-6a')->assertOk();
        $reward = $this->createProduct('Reward F');
        $foreignScheme = $this->createScheme('Tenant A scheme', '1000.0000', $reward);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('valid-6b')->assertOk();

        $this->assertValidationFails(
            fn () => $this->validate('9000.0000', [], [$foreignScheme]),
            'applied_scheme_ulids'
        );
    }

    public function test_auto_scheme_is_included_even_when_not_selected(): void
    {
        $this->signInOwner('valid-7')->assertOk();
        $reward = $this->createProduct('Reward G');
        $scheme = $this->createScheme('Auto scheme', '5000.0000', $reward, ['apply_mode' => 'auto']);

        $result = $this->validate('6000.0000', [], []);

        $this->assertCount(1, $result['scheme_lines']);
        $this->assertTrue($result['scheme_lines'][0]['auto_applied']);
        $this->assertSame($scheme, $result['scheme_lines'][0]['scheme']->ulid);
    }

    public function test_explicitly_selected_auto_scheme_is_not_added_twice(): void
    {
        $this->signInOwner('valid-8')->assertOk();
        $reward = $this->createProduct('Reward H');
        $scheme = $this->createScheme('Auto scheme', '5000.0000', $reward, ['apply_mode' => 'auto']);

        $result = $this->validate('6000.0000', [], [$scheme]);

        $this->assertCount(1, $result['scheme_lines']);
        $this->assertFalse($result['scheme_lines'][0]['auto_applied']);
    }

    public function test_salesman_scheme_is_not_added_when_the_salesman_skips_it(): void
    {
        $this->signInOwner('valid-9')->assertOk();
        $reward = $this->createProduct('Reward I');
        $this->createScheme('Salesman scheme', '1000.0000', $reward, ['apply_mode' => 'salesman']);

        // Skip = submit no scheme ULID at all.
        $this->assertCount(0, $this->validate('9000.0000', [], [])['scheme_lines']);
    }

    public function test_packaging_free_line_is_accepted_within_limit(): void
    {
        $this->signInOwner('valid-10')->assertOk();
        $bag = $this->createProduct('Shopping Bag', [
            'is_packaging' => true,
            'max_free_qty_per_sale' => '2.000000',
        ]);

        $result = $this->validate('100.0000', [[
            'product_ulid' => $bag,
            'qty' => '2',
            'line_kind' => 'free_packaging',
        ]], []);

        $this->assertCount(0, $result['scheme_lines']);
        $this->assertCount(1, $result['packaging_lines']);
        $this->assertSame('free_packaging', $result['packaging_lines'][0]['line_kind']);
        $this->assertSame($bag, $result['packaging_lines'][0]['product']->ulid);
    }

    public function test_packaging_free_line_above_limit_is_rejected(): void
    {
        $this->signInOwner('valid-11')->assertOk();
        $bag = $this->createProduct('Shopping Bag L', [
            'is_packaging' => true,
            'max_free_qty_per_sale' => '2.000000',
        ]);

        $this->assertValidationFails(
            fn () => $this->validate('100.0000', [[
                'product_ulid' => $bag,
                'qty' => '3',
                'line_kind' => 'free_packaging',
            ]], []),
            'free_lines.0.qty'
        );
    }

    public function test_non_packaging_product_cannot_be_given_as_a_free_line(): void
    {
        $this->signInOwner('valid-12')->assertOk();
        $normal = $this->createProduct('Normal Item');

        $this->assertValidationFails(
            fn () => $this->validate('100.0000', [[
                'product_ulid' => $normal,
                'qty' => '1',
                'line_kind' => 'free_packaging',
            ]], []),
            'free_lines.0.product_ulid'
        );
    }

    public function test_free_line_quantity_must_be_greater_than_zero(): void
    {
        $this->signInOwner('valid-13')->assertOk();
        $bag = $this->createProduct('Shopping Bag Z', ['is_packaging' => true]);

        $this->assertValidationFails(
            fn () => $this->validate('100.0000', [[
                'product_ulid' => $bag,
                'qty' => '0',
                'line_kind' => 'free_packaging',
            ]], []),
            'free_lines.0.qty'
        );
    }

    public function test_packaging_and_scheme_lines_coexist(): void
    {
        $this->signInOwner('valid-14')->assertOk();
        $bag = $this->createProduct('Gift Box', ['is_packaging' => true]);
        $reward = $this->createProduct('Reward J');
        $scheme = $this->createScheme('Spend 5000', '5000.0000', $reward);

        $result = $this->validate('6000.0000', [[
            'product_ulid' => $bag,
            'qty' => '1',
            'line_kind' => 'free_packaging',
        ]], [$scheme]);

        $this->assertCount(1, $result['packaging_lines']);
        $this->assertCount(1, $result['scheme_lines']);
    }

    /**
     * TenantContext is a scoped binding hydrated by the `tenant` middleware, so
     * the authenticated tenant requests above have already primed it.
     *
     * @param  list<array<string, mixed>>  $freeLines
     * @param  list<string>  $appliedSchemeUlids
     * @return array{packaging_lines: list<array<string, mixed>>, scheme_lines: list<array<string, mixed>>}
     */
    private function validate(string $subtotal, array $freeLines, array $appliedSchemeUlids): array
    {
        return app(ValidateAppliedSaleOffersAction::class)
            ->execute($subtotal, $freeLines, $appliedSchemeUlids);
    }

    private function assertValidationFails(callable $callback, string $expectedKey): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                $expectedKey,
                $exception->errors(),
                'Expected a validation error on "'.$expectedKey.'", got: '.implode(', ', array_keys($exception->errors()))
            );

            return;
        }

        $this->fail('Expected a validation failure on "'.$expectedKey.'".');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProduct(string $name, array $overrides = []): string
    {
        $response = $this->postJson('/api/products', array_merge([
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ], $overrides))->assertCreated();

        return $response->json('ulid');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createScheme(string $name, string $minAmount, string $rewardProductUlid, array $overrides = []): string
    {
        $response = $this->postJson('/api/sale-schemes', array_merge([
            'name' => $name,
            'apply_mode' => 'salesman',
            'min_sale_amount' => $minAmount,
            'reward_product_ulid' => $rewardProductUlid,
            'max_reward_qty' => '1.000000',
        ], $overrides))->assertCreated();

        return $response->json('ulid');
    }

    private function unitUlid(string $code): string
    {
        $units = $this->getJson('/api/units')->assertOk()->json();
        foreach ($units as $unit) {
            if ($unit['code'] === $code) {
                return $unit['ulid'];
            }
        }

        $this->fail('Missing unit '.$code);
    }
}
