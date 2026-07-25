<?php

use Hasyirin\RunningNumber\Concerns\ResolvesType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

enum TestPermitType: string
{
    case Residential = 'residential';
}

class TestResolvesTypeHost
{
    use ResolvesType;

    public function resolve($type): string
    {
        return $this->resolveType($type);
    }
}

class TestBranchModel extends Model
{
    protected $table = 'test_branches';

    public $timestamps = false;

    protected $guarded = [];
}

it('resolves a plain string as-is', function () {
    expect((new TestResolvesTypeHost)->resolve('invoice'))->toBe('invoice');
});

it('resolves a BackedEnum to its value', function () {
    expect((new TestResolvesTypeHost)->resolve(TestPermitType::Residential))->toBe('residential');
});

it('resolves a Model instance via getMorphClass with a registered morph map alias', function () {
    Relation::morphMap(['branch' => TestBranchModel::class]);

    $branch = new TestBranchModel(['id' => 5]);

    expect((new TestResolvesTypeHost)->resolve($branch))->toBe('branch');

    Relation::morphMap([], merge: false);
});

it('resolves a Model class-string via getMorphClass without a registered alias', function () {
    expect((new TestResolvesTypeHost)->resolve(TestBranchModel::class))->toBe(TestBranchModel::class);
});

it('joins an array of mixed parts with a colon, without appending an instance id', function () {
    Relation::morphMap(['branch' => TestBranchModel::class]);

    $branch = new TestBranchModel(['id' => 5]);

    expect((new TestResolvesTypeHost)->resolve([TestPermitType::Residential, $branch, $branch->id]))
        ->toBe('residential:branch:5');

    Relation::morphMap([], merge: false);
});

it('accepts a Collection the same way it accepts an array', function () {
    expect((new TestResolvesTypeHost)->resolve(collect(['permit', 'branch', 5])))->toBe('permit:branch:5');
});

it('throws when the array contains an unsupported type', function () {
    expect(fn () => (new TestResolvesTypeHost)->resolve(['permit', new stdClass]))
        ->toThrow(UnexpectedValueException::class);
});
