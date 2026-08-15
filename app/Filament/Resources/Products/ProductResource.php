<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use App\Support\Locales;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;

class ProductResource extends Resource
{
    use Translatable;

    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Readings and passes';

    /**
     * The language tabs, read when the form is built rather than when the panel
     * is registered.
     *
     * The plugin takes its list once, at registration, which is stale the
     * moment a language is added or removed. Reading the registry here means a
     * new language appears as soon as its row exists.
     *
     * @return array<int, string>
     */
    public static function getTranslatableLocales(): array
    {
        return Locales::codes();
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    /**
     * Products are seeded, never created here.
     *
     * A create form would have to ask for a key, a type and a fulfillment mode,
     * which are structure rather than content and would mean nothing to
     * Jennifer. Anything new is a developer adding a row in a deploy, alongside
     * the frontend work that makes it sellable. See CLAUDE.md section 4.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Nor deleted. An order will reference a product from M5 onward, and the
     * way to take one off sale is to unpublish it.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
