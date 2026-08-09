<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mail\PasswordResetLink;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'viewing_room_access_at' => 'datetime',
        ];
    }

    /**
     * Send the reset link ourselves rather than through Laravel's notification.
     *
     * The framework's version builds a link back to this application, which
     * renders nothing a customer can use. Ours points at the website and comes
     * from the support address like every other message a person receives.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        Mail::to($this->email)->send(new PasswordResetLink($this, $token));
    }

    /**
     * Whether this customer may watch the Living Tarot videos.
     *
     * The pass is bought once and never expires, so the presence of the stamp is
     * the whole answer. If it ever becomes a subscription, this is the one place
     * that has to learn about time.
     */
    public function hasViewingRoomAccess(): bool
    {
        return $this->viewing_room_access_at !== null;
    }

    /**
     * Let somebody in, recording when.
     *
     * Called by M5 when the pass is paid for, and by support putting right an
     * order that went wrong. Never by anything a customer can reach.
     *
     * Access already granted is left as it was rather than refreshed, so the date
     * keeps meaning when they first got in.
     */
    public function grantViewingRoomAccess(): void
    {
        if ($this->hasViewingRoomAccess()) {
            return;
        }

        $this->forceFill(['viewing_room_access_at' => now()])->save();
    }
}
