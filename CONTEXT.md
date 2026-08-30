# The World Tarot, backend

The words this repo uses for things it would otherwise call three names.

**Deliberately not the whole glossary.** The domain words currently live in
`CLAUDE.md` and in `AGENT-BRIEF-architecture-tickets.md`, and copying them here
where none of the three would be authoritative is the drift this file exists
against. Moving them properly is its own piece of work. A term arrives here
when something has actually had to name it.

## Accounts

**Unclaimed**:
An account that has never had a password. It is what a purchase creates for
somebody who has never signed up, it is the only kind of account a claim link is
worth sending to, and it is what makes that link work exactly once. Asked
through `Customer::isUnclaimed()` and never by comparing the column, because
three modules used to spell out `password === null` separately and agreed only
because all three happened to write it the same way.

The negative is the named state on purpose: every caller asks about the
negative, so naming the positive would mean three call sites negate.

_Avoid_: passwordless, incomplete account, guest account (a guest is a buyer
with no session, which is a different thing and belongs to the frontend's
glossary), pending account

**Claim**:
The one moment an unclaimed account stops being unclaimed, owned by
`App\Actions\ClaimAccount` and by nothing else. A claim is not a reset: nothing
is being replaced, and no other device is signed out.

_Avoid_: activate, register (registering makes an account; claiming finishes one
that already exists), set up

**Claim link**:
The link in the paid-order mail inviting the buyer of an unclaimed account to
choose a first password. Minted by `MarkOrderPaid`, carried by
`App\Mail\SetPasswordLink`, and pointed at the website's `/set-password/`
because this application renders nothing a customer can use. It lasts an hour
and works exactly once.

_Avoid_: activation link, welcome link, invite, set password link (that is the
mailable's class name, not the thing)
