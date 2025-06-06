<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hyperf\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\DatabasePresenceVerifier;
use Hypervel\Validation\Rules\Unique;
use Hypervel\Validation\Validator;

/**
 * @internal
 * @coversNothing
 */
class ValidationUniqueRuleTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    public function testItCorrectlyFormatsAStringVersionOfTheRule()
    {
        $rule = new Unique('table');
        $rule->where('foo', 'bar');
        $this->assertSame('unique:table,NULL,NULL,id,foo,"bar"', (string) $rule);

        $rule = new Unique(EloquentModelStub::class);
        $rule->where('foo', 'bar');
        $this->assertSame('unique:table,NULL,NULL,id,foo,"bar"', (string) $rule);

        $rule = new Unique(NoTableName::class);
        $rule->where('foo', 'bar');
        $this->assertSame('unique:no_table_names,NULL,NULL,id,foo,"bar"', (string) $rule);

        $rule = new Unique('Hypervel\Tests\Validation\NoTableName');
        $rule->where('foo', 'bar');
        $this->assertSame('unique:no_table_names,NULL,NULL,id,foo,"bar"', (string) $rule);

        $rule = new Unique(ClassWithNonEmptyConstructor::class);
        $rule->where('foo', 'bar');
        $this->assertSame('unique:' . ClassWithNonEmptyConstructor::class . ',NULL,NULL,id,foo,"bar"', (string) $rule);

        $rule = new Unique('table', 'column');
        $rule->ignore('Taylor, Otwell', 'id_column');
        $rule->where('foo', 'bar');
        $this->assertSame('unique:table,column,"Taylor, Otwell",id_column,foo,"bar"', (string) $rule);

        $rule = new Unique(PrefixedTableEloquentModelStub::class);
        $this->assertSame('unique:' . PrefixedTableEloquentModelStub::class . ',NULL,NULL,id', (string) $rule);

        $rule = new Unique(EloquentModelStub::class, 'column');
        $rule->ignore('Taylor, Otwell', 'id_column');
        $rule->where('foo', 'bar');
        $this->assertSame('unique:table,column,"Taylor, Otwell",id_column,foo,"bar"', (string) $rule);

        $rule = new Unique(EloquentModelStub::class, 'column');
        $rule->where('foo', 'bar');
        $rule->when(true, function ($rule) {
            $rule->ignore('Taylor, Otwell', 'id_column');
        });
        $rule->unless(true, function ($rule) {
            $rule->ignore('Chris', 'id_column');
        });
        $this->assertSame('unique:table,column,"Taylor, Otwell",id_column,foo,"bar"', (string) $rule);

        $rule = new Unique('table', 'column');
        $rule->ignore('Taylor, Otwell"\'..-"', 'id_column');
        $rule->where('foo', 'bar');
        $this->assertSame('unique:table,column,"Taylor, Otwell\"\\\'..-\"",id_column,foo,"bar"', (string) $rule);
        $this->assertSame('Taylor, Otwell"\'..-"', stripslashes(str_getcsv('table,column,"Taylor, Otwell\"\\\'..-\"",id_column,foo,"bar"', escape: '\\')[2]));
        $this->assertSame('id_column', stripslashes(str_getcsv('table,column,"Taylor, Otwell\"\\\'..-\"",id_column,foo,"bar"', escape: '\\')[3]));

        $rule = new Unique('table', 'column');
        $rule->ignore(null, 'id_column');
        $rule->where('foo', 'bar');
        $this->assertSame('unique:table,column,NULL,id_column,foo,"bar"', (string) $rule);

        $model = new EloquentModelStub(['id_column' => 1]);

        $rule = new Unique('table', 'column');
        $rule->ignore($model);
        $rule->where('foo', 'bar');
        $this->assertSame('unique:table,column,"1",id_column,foo,"bar"', (string) $rule);

        $rule = new Unique('table', 'column');
        $rule->ignore($model, 'id_column');
        $rule->where('foo', 'bar');
        $this->assertSame('unique:table,column,"1",id_column,foo,"bar"', (string) $rule);

        $rule = new Unique('table');
        $rule->where('foo', '"bar"');
        $this->assertSame('unique:table,NULL,NULL,id,foo,"""bar"""', (string) $rule);

        $rule = new Unique(EloquentModelWithConnection::class, 'column');
        $rule->where('foo', 'bar');
        $this->assertSame('unique:mysql.table,column,NULL,id,foo,"bar"', (string) $rule);
    }

    public function testItIgnoresSoftDeletes()
    {
        $rule = new Unique('table');
        $rule->withoutTrashed();
        $this->assertSame('unique:table,NULL,NULL,id,deleted_at,"NULL"', (string) $rule);

        $rule = new Unique('table');
        $rule->withoutTrashed('softdeleted_at');
        $this->assertSame('unique:table,NULL,NULL,id,softdeleted_at,"NULL"', (string) $rule);
    }

    public function testItOnlyTrashedSoftDeletes()
    {
        $rule = new Unique('table');
        $rule->onlyTrashed();
        $this->assertSame('unique:table,NULL,NULL,id,deleted_at,"NOT_NULL"', (string) $rule);

        $rule = new Unique('table');
        $rule->onlyTrashed('softdeleted_at');
        $this->assertSame('unique:table,NULL,NULL,id,softdeleted_at,"NOT_NULL"', (string) $rule);
    }

    public function testItHandlesNullPrimaryKeyInIgnoreModel()
    {
        $model = new EloquentModelStub(['id_column' => null]);

        $rule = new Unique('table', 'column');
        $rule->ignore($model);
        $rule->where('foo', 'bar');
        $this->assertSame('unique:table,column,NULL,id_column,foo,"bar"', (string) $rule);

        $rule = new Unique('table', 'column');
        $rule->ignore($model, 'id_column');
        $rule->where('foo', 'bar');
        $this->assertSame('unique:table,column,NULL,id_column,foo,"bar"', (string) $rule);
    }

    public function testItHandlesWhereWithSpecialValues()
    {
        $rule = new Unique('table', 'column');
        $rule->where('foo', null);
        $this->assertSame('unique:table,column,NULL,id,foo,"NULL"', (string) $rule);

        $rule = new Unique('table', 'column');
        $rule->whereNot('foo', 'bar');
        $this->assertSame('unique:table,column,NULL,id,foo,"!bar"', (string) $rule);

        $rule = new Unique('table', 'column');
        $rule->whereNull('foo');
        $this->assertSame('unique:table,column,NULL,id,foo,"NULL"', (string) $rule);

        $rule = new Unique('table', 'column');
        $rule->whereNotNull('foo');
        $this->assertSame('unique:table,column,NULL,id,foo,"NOT_NULL"', (string) $rule);

        $rule = new Unique('table', 'column');
        $rule->where('foo', 0);
        $this->assertSame('unique:table,column,NULL,id,foo,"0"', (string) $rule);
    }

    public function testItValidatesUniqueRuleWithWhereInAndWhereNotIn()
    {
        EloquentModelStub::create(['id_column' => 1, 'type' => 'admin']);
        EloquentModelStub::create(['id_column' => 2, 'type' => 'moderator']);
        EloquentModelStub::create(['id_column' => 3, 'type' => 'editor']);
        EloquentModelStub::create(['id_column' => 4, 'type' => 'user']);

        $rule = new Unique(table: 'table', column: 'id_column');
        $rule->whereIn(column: 'type', values: ['admin', 'moderator', 'editor'])
            ->whereNotIn(column: 'type', values: ['editor']);

        $trans = $this->getArrayTranslator();
        $v = new Validator($trans, [], ['id_column' => $rule]);
        $v->setPresenceVerifier(new DatabasePresenceVerifier(
            $this->app->get(ConnectionResolverInterface::class)
        ));

        $v->setData(['id_column' => 1]);
        $this->assertFalse($v->passes());

        $v->setData(['id_column' => 2]);
        $this->assertFalse($v->passes());

        $v->setData(['id_column' => 3]);
        $this->assertTrue($v->passes());

        $v->setData(['id_column' => 4]);
        $this->assertTrue($v->passes());

        $v->setData(['id_column' => 5]);
        $this->assertTrue($v->passes());
    }

    protected function getArrayTranslator(): Translator
    {
        return new Translator(
            new ArrayLoader(),
            locale: 'en'
        );
    }
}

class EloquentModelStub extends Model
{
    protected ?string $table = 'table';

    protected string $primaryKey = 'id_column';

    protected array $guarded = [];
}

class PrefixedTableEloquentModelStub extends Model
{
    protected ?string $table = 'public.table';

    protected string $primaryKey = 'id_column';

    protected array $guarded = [];
}

class NoTableName extends Model
{
    protected array $guarded = [];

    public bool $timestamps = false;
}

class ClassWithNonEmptyConstructor
{
    private $bar;

    private $baz;

    public function __construct($bar, $baz)
    {
        $this->bar = $bar;
        $this->baz = $baz;
    }
}

class EloquentModelWithConnection extends EloquentModelStub
{
    protected ?string $connection = 'mysql';
}
