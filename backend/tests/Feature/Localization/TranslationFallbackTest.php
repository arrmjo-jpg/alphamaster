<?php

declare(strict_types=1);

use App\Modules\Core\Models\BaseModel;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LanguageSeeder::class);

    Schema::create('dummy_articles', function (Blueprint $table): void {
        $table->ulid('id')->primary();
        $table->timestamps();
    });

    Schema::create('dummy_article_translations', function (Blueprint $table): void {
        $table->ulid('id')->primary();
        $table->string('dummy_article_id', 26);
        $table->string('locale', 10);
        $table->string('title');
        $table->timestamps();

        $table->unique(['dummy_article_id', 'locale']);
    });
});

afterEach(function (): void {
    Schema::dropIfExists('dummy_article_translations');
    Schema::dropIfExists('dummy_articles');
});

test('changing database default language changes translation fallback without changing config app.locale', function (): void {
    // Define dummy translation model
    $translationClass = new class extends BaseModel
    {
        protected $table = 'dummy_article_translations';

        protected $fillable = ['dummy_article_id', 'locale', 'title'];
    };

    // Define dummy translatable article model
    $articleClass = new class extends BaseModel
    {
        use HasTranslations;

        protected $table = 'dummy_articles';

        public function translations(): HasMany
        {
            return $this->hasMany(new class extends BaseModel
            {
                protected $table = 'dummy_article_translations';

                protected $fillable = ['dummy_article_id', 'locale', 'title'];
            }::class, 'dummy_article_id');
        }
    };

    $article = $articleClass::create([]);

    // Create English and Arabic translations
    DB::table('dummy_article_translations')->insert([
        [
            'id' => (string) Str::ulid(),
            'dummy_article_id' => $article->id,
            'locale' => 'en',
            'title' => 'English Title',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => (string) Str::ulid(),
            'dummy_article_id' => $article->id,
            'locale' => 'ar',
            'title' => 'العنوان بالعربية',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // 1. Initial State: DB default language is 'en', config('app.locale') is 'en'
    expect(config('app.locale'))->toBe('en');

    // Requesting an unsupported locale ('fr') falls back to DB default ('en')
    expect($article->getTranslation('title', 'fr'))->toBe('English Title');

    // 2. Change the DB default language to 'ar' atomically
    DB::transaction(function (): void {
        Language::where('is_default', true)->update(['is_default' => false]);
        $arabic = Language::where('code', 'ar')->firstOrFail();
        $arabic->update(['is_default' => true]);
    });

    // Verify config('app.locale') did NOT change
    expect(config('app.locale'))->toBe('en');

    // 3. Requesting unsupported locale ('fr') now falls back to DB default ('ar') without changing config
    expect($article->getTranslation('title', 'fr'))->toBe('العنوان بالعربية');
});
