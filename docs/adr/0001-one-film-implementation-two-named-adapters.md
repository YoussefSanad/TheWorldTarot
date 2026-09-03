# 1. One film implementation, two named adapters

Decided 25 August 2026. Accepted.

## What was asked

`FullFilmRevealVideo` and `ViewingRoomFilm` are the same class twice. With comments
stripped they differ in three lines: the namespace, the class name, and one config
key for the expiry. Same constructor, same eligibility, same `isPlayable` guard,
same signing, same `Playback`.

An architecture review asked whether the duplication was earning anything.

## What is actually being protected

The reveal is public and the Viewing Room is sold. Changing what anybody can get
must never reach into what somebody paid for, and lengthening what a paying viewer
gets must never lengthen a public credential. That property is not negotiable and
nothing here weakens it.

The review's finding was that the two classes are not what secures it. It is
already secured a layer above them, twice over:

- **Which film plays**, two config files, `reveal.source` and
  `viewing-room.source`, each naming its own class
- **How long the credential lives**, two env-backed keys,
  `BUNNY_REVEAL_TTL` and `BUNNY_PLAYBACK_TTL`, each carrying its own written
  reasoning in `config/services.php`

Both remain independently replaceable whatever sits behind them. The classes are
the thing the config names, not the mechanism keeping the decisions apart.

## The cost of the obvious fix, which is why it was not taken

The obvious collapse is one class taking its expiry key as a constructor argument.
It was declined.

Today `config/reveal.php` holds exactly one thing, a class name, and that name
fully determines what happens. A constructor argument breaks that. The config value
stops being self-sufficient, and either the file grows a second key or the wiring
moves into `AppServiceProvider`, which would then be special-casing a class it is
supposed to treat as opaque. Replacing what plays would no longer be one line in
one file.

That property is worth more than the forty lines it costs.

## Decision

A shared implementation holds everything both adapters do: eligibility, the
`isPlayable` guard, the reasoning about a card whose film is being replaced, the
IPv4 caveat, and the signing itself. `FullFilmRevealVideo` and `ViewingRoomFilm`
survive as subclasses declaring nothing but which expiry key they read.

Both names stay. There is a great deal of recorded reasoning attached to them:
why the reveal is public, why the paywall's half was the half with no seam, and
what each accepts in writing. That reasoning belongs on the name it describes,
not merged into a third one.

Config is untouched. Both files still name a bare class.

## What this gives up

Inheritance rather than composition, for two classes with no behaviour of their
own. Accepted because the alternative that avoids it is two pass-through adapters
of ten lines each, which is the shallow module this whole exercise is trying not
to build.

A subclass could in principle override more than its expiry key. Nothing stops it,
and nothing is expected to. The day one needs to, it should stop being a subclass
and become its own adapter against `FilmSource`, which is what the seam is for
and which no longer costs anything now that the shared implementation exists.

## Why now rather than later

`FilmSource` is about to gain a method: a source will declare what it can play as
a `Condition`, so the Viewing Room's endpoint can read one declaration instead of
composing the rule at the call site. Left alone, that method gets written twice on
the day it arrives. See #28 and #32.

## What this does not settle

That the reveal and the Viewing Room serve the same film today. That is a separate
decision, taken knowingly on 2 August 2026 and recorded in
`docs/plans/reveal-video-plan.md`. This record is about how the two adapters are
built, not about what they play.
