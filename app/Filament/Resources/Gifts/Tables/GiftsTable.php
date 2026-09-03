<?php

namespace App\Filament\Resources\Gifts\Tables;

use App\Mail\GiftToRedeem;
use App\Models\Gift;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;

class GiftsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // **Longest outstanding at the top**, which is the same way
            // `ReadingsTable` sorts and the opposite of `OrdersTable`. Orders
            // is newest first because what she opens it to find is whatever
            // just came in. This screen answers the other question, which
            // present has been sitting unclaimed longest, and sorting it the
            // other way would bury exactly the row worth looking at.
            ->defaultSort('outstanding', 'desc')
            ->columns([
                // How long it has been on the books, which is the column ADR
                // 0006 says turns a liability into something anybody can act
                // on. A date is a thing to work out; "3 months" is a thing to
                // notice.
                //
                // **Neutral, like the badge.** An unredeemed gift is not a
                // task, whatever its age. Nothing here goes orange, because
                // there is no number of days at which somebody has done
                // something wrong.
                TextColumn::make('outstanding')
                    ->label('Outstanding for')
                    ->state(fn (Gift $record): string => self::outstandingFor($record))
                    ->badge()
                    ->color('gray')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::orderByAge($query, $direction)),

                // **Printed the way the recipient sees it.** The hyphens are
                // `Gift::printedCode`'s, so the panel and the mail print one
                // credential the same way and she can read one off against the
                // other. Copyable because the next thing she does with it is
                // paste it into a reply.
                TextColumn::make('code')
                    ->label('Code')
                    ->state(fn (Gift $record): ?string => $record->printedCode())
                    ->copyable()
                    ->fontFamily('mono')

                    // Read the way the endpoints read a typed code, and asked
                    // of the model rather than spelled out here. It is the
                    // model that knows a search normalising to nothing has to
                    // find nothing rather than everything.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->containingTypedCode($search)),

                TextColumn::make('orderLine.product_key')
                    ->label('Reading')
                    ->formatStateUsing(fn (Gift $record): string => self::nameOf($record)),

                // **The only address in this transaction nothing can check.**
                // The buyer typed it for somebody else and never receives the
                // code, so nothing on this side can tell that the mail arrived
                // nowhere. It is here so that a person can.
                TextColumn::make('recipient_email')
                    ->label('Sent to')
                    ->searchable(),

                // Who it is from, and not a name. It is what the mail is signed
                // and subject lined with, so "Mum" is a truer answer than what
                // is on the card.
                TextColumn::make('signature')
                    ->label('From')
                    ->searchable(),

                // The same instant the column above counts from, printed
                // absolutely. Two readings of one moment, so they cannot come
                // to disagree.
                TextColumn::make('created_at')
                    ->label('Bought')
                    ->dateTime('j M Y')
                    ->sortable(),

                TextColumn::make('redeemed_at')
                    ->label('Redeemed')
                    ->dateTime('j M Y')
                    ->placeholder('Not yet')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // **Defaulting to outstanding**, ADR 0006, which is the whole
                // point of the screen. A redeemed gift is an obligation
                // discharged, and opening on everything ever gifted would
                // answer a question about sales rather than about what is owed.
                TernaryFilter::make('redeemed_at')
                    ->label('Redeemed')
                    ->placeholder('All gifts')
                    ->trueLabel('Already redeemed')
                    ->falseLabel('Still outstanding')

                    // **Outstanding is `Gift::scopeOutstanding`**, and not a
                    // second spelling of it here. The badge counts the same
                    // scope, and a number in the sidebar that could disagree
                    // with the list under it would be worse than no number.
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('redeemed_at'),
                        false: fn (Builder $query): Builder => $query->outstanding(),
                    )
                    ->default(false),
            ])
            ->recordActions([
                self::resend(),
            ])
            // No create, no delete, no bulk anything. A gift is the record that
            // somebody is owed a reading.
            ->toolbarActions([]);
    }

    /**
     * Sending the code again.
     *
     * **The same mailable settlement sends, and deliberately not a second one
     * shaped like it.** The case this screen exists for is a recipient whose
     * address bounced, and what she owes them is the message they never got:
     * the code, the link and the signature it was signed with.
     *
     * **It cannot be sent anywhere else**, which is the point of it being one
     * button rather than a form. `GiftToRedeem` addresses the gift's own
     * recipient, so resending re-delivers to the inbox the buyer chose and
     * hands nobody a second live credential. Retyping the address is revoke and
     * reissue, refused by ADR 0006 by name.
     */
    private static function resend(): Action
    {
        return Action::make('resend')
            ->label('Resend')
            ->icon(Heroicon::OutlinedEnvelope)
            ->requiresConfirmation()
            ->modalHeading('Send this gift code again?')
            ->modalDescription(fn (Gift $record): string => "The same email goes out again to {$record->recipient_email}, carrying the same code. It cannot be sent to a different address, and nothing here can tell you whether it arrived.")
            ->modalSubmitActionLabel('Yes, send it again')

            // **Hidden once it has been spent.** The link in that mail leads to
            // a code that no longer resolves, so sending it again would tell
            // somebody they have a present and then refuse it.
            ->visible(fn (Gift $record): bool => ! $record->isRedeemed())
            ->action(function (Gift $record): void {
                Mail::to($record->recipient_email)->send(new GiftToRedeem($record));

                Notification::make()
                    ->success()
                    ->title('Sent again to '.$record->recipient_email)
                    ->send();
            });
    }

    /**
     * How long the obligation has been on the books, in the words somebody
     * carrying it would use.
     *
     * **Counted from when it was bought rather than from when the code was
     * sent**, and the difference is the checkout: a row is written when the
     * buyer types their four fields and minted minutes later when the money
     * arrives. Anchoring on the purchase costs that gap and buys a column with
     * no join behind it, and the gap is below the resolution of a number
     * measured in days and months.
     */
    private static function outstandingFor(Gift $record): string
    {
        if ($record->isRedeemed()) {
            return 'Redeemed';
        }

        return $record->created_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE);
    }

    /**
     * Oldest first when the column is sorted descending, because a longer
     * outstanding means an earlier purchase and the two run opposite ways.
     *
     * @param  Builder<Gift>  $query
     * @return Builder<Gift>
     */
    private static function orderByAge(Builder $query, string $direction): Builder
    {
        return $query->orderBy('gifts.created_at', $direction === 'desc' ? 'asc' : 'desc');
    }

    /**
     * What the customer saw this reading called.
     *
     * **A code for a withdrawn product still redeems**, so a row that could not
     * say what was owed would be a liability nobody can read. A retired reading
     * is soft deleted and `OrderLine::product` carries `withTrashed` for
     * exactly this, so retirement alone never reaches the fallback.
     *
     * The key behind it is for a product row that is gone rather than retired,
     * which `order_lines.product_id` restricts on delete and nothing here can
     * produce. It is a name rather than an empty cell if that ever changes.
     * `GiftToRedeem` falls back the same way and for the same reason.
     */
    private static function nameOf(Gift $record): string
    {
        $line = $record->orderLine;

        return $line->product?->name ?: $line->product_key->value;
    }
}
