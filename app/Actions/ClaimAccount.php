<?php

namespace App\Actions;

use App\Enums\ClaimOutcome;
use App\Models\Customer;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * The one moment an account stops being unclaimed.
 *
 * An action rather than a model method, beside `MarkOrderPaid` and
 * `MarkOrderFulfilled`, which is where this codebase puts the one moment
 * something happens when more than one road reaches it.
 *
 * ## What this exists for
 *
 * **The condition and the write have to be in one scope.** They were not:
 * `SetPasswordController` asked whether the account had ever had a password
 * through its own query, and the write happened later, inside the broker's
 * callback. Two claims arriving together both read null, both passed, and the
 * loser overwrote the winner's password. The window was one broker call wide.
 *
 * So the transaction wraps the broker, the callback re-reads the row with
 * `lockForUpdate`, and a row that has been claimed in the meantime is refused
 * from in there rather than from out here. **The lock is what makes the
 * concurrent case safe**, and it is proved sequentially in
 * `ClaimAccountTest` with the reasoning written down, because a two connection
 * test would prove that Postgres implements `lockForUpdate` rather than
 * proving anything of ours.
 *
 * ## What it deliberately does not own
 *
 * Minting the token and sending the mail stay in `MarkOrderPaid`. The
 * reasoning at that call site, that a claim link sent to a claimed account is
 * a phishing shaped tool pointed at our own customers, is reasoning about why
 * it asks rather than about what claiming is. Pulling the mint across would
 * put `Password::broker` and a mailable behind an interface that has to serve
 * password reset too, which is a larger seam decision than this needs.
 *
 * `ResetPasswordController` is untouched for the same kind of reason. It
 * shares six lines with this one, and those six lines are the framework's
 * prescribed way of writing a password rather than this codebase's rule.
 */
class ClaimAccount
{
    /**
     * @return ClaimOutcome What the endpoint should tell them, and nothing more
     */
    public function __invoke(string $email, string $token, #[\SensitiveParameter] string $password): ClaimOutcome
    {
        $customer = Customer::firstWhere('email', $email);

        // An unknown address and a claimed account are one answer, so that this
        // cannot be used to ask which of the two applies. The broker is never
        // reached for either, because its own refusal names the difference.
        if ($customer === null || ! $customer->isUnclaimed()) {
            return ClaimOutcome::AlreadyClaimed;
        }

        try {
            $status = DB::transaction(fn (): mixed => Password::broker('customers')->reset(
                [
                    'email' => $email,
                    'password' => $password,
                    'password_confirmation' => $password,
                    'token' => $token,
                ],
                $this->write(...),
            ));
        } catch (AccountAlreadyClaimed) {
            return ClaimOutcome::AlreadyClaimed;
        }

        return $status === Password::PasswordReset
            ? ClaimOutcome::Claimed
            : ClaimOutcome::LinkNotValid;
    }

    /**
     * The write, under the lock that makes the check above mean something.
     *
     * The row is read again here rather than trusting the one the broker
     * resolved, because that one was read before the transaction opened and
     * says nothing about what is true now.
     */
    private function write(Customer $customer, #[\SensitiveParameter] string $password): void
    {
        $locked = Customer::whereKey($customer->getKey())->lockForUpdate()->first();

        if ($locked === null || ! $locked->isUnclaimed()) {
            throw AccountAlreadyClaimed::betweenTheCheckAndTheWrite();
        }

        $locked->forceFill([
            // The `hashed` cast does the hashing on assignment, which is why
            // this is not a conditional `whereNull('password')->update(...)`.
            // That is simpler and just as atomic, and it costs the cast, which
            // is how the two places that write a password come apart later.
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        // **Nothing else is signed out.** A claim is somebody arriving for the
        // first time, not somebody who believes another person has their
        // password, so there is no session to end. That is the one line where
        // this and a reset genuinely differ.
        event(new PasswordReset($locked));
    }
}
