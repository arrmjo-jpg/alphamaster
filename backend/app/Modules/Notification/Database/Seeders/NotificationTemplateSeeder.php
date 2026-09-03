<?php

declare(strict_types=1);

namespace App\Modules\Notification\Database\Seeders;

use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

class NotificationTemplateSeeder extends Seeder
{
    /**
     * Baseline templates, in the two languages the platform ships with.
     *
     * Every registered notification type gets one, so a dispatch can never fail for
     * want of a template on a fresh installation.
     *
     * @return array<string, array<string, array{subject: string, body: string}>>
     */
    private function definitions(): array
    {
        return [
            NotificationType::SECURITY_ALERT->value => [
                'en' => [
                    'subject' => 'Security alert on your account',
                    'body' => 'Hello :name, we detected a security event on your account: :event. If this was not you, change your password immediately.',
                ],
                'ar' => [
                    'subject' => 'تنبيه أمني على حسابك',
                    'body' => 'مرحبًا :name، رصدنا حدثًا أمنيًا على حسابك: :event. إذا لم تكن أنت، غيّر كلمة المرور فورًا.',
                ],
            ],
            NotificationType::ACCOUNT_UPDATED->value => [
                'en' => [
                    'subject' => 'Your account was updated',
                    'body' => 'Hello :name, your account details were changed: :event.',
                ],
                'ar' => [
                    'subject' => 'تم تحديث حسابك',
                    'body' => 'مرحبًا :name، تم تغيير بيانات حسابك: :event.',
                ],
            ],
            NotificationType::ADMIN_ANNOUNCEMENT->value => [
                'en' => [
                    'subject' => ':subject',
                    'body' => ':body',
                ],
                'ar' => [
                    'subject' => ':subject',
                    'body' => ':body',
                ],
            ],
        ];
    }

    /**
     * Idempotent and non-destructive, like every other seeder here: it creates what is
     * missing and never overwrites wording an operator has edited.
     */
    public function run(): void
    {
        foreach ($this->definitions() as $type => $translations) {
            $template = NotificationTemplate::query()->firstOrCreate(
                ['type' => $type],
                ['is_active' => true],
            );

            foreach ($translations as $locale => $content) {
                $exists = $template->translations()->where('locale', $locale)->exists();

                if (! $exists) {
                    $template->setTranslation($locale, $content);
                }
            }
        }
    }
}
