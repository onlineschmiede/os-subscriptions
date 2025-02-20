<?php declare(strict_types=1);

namespace OsSubscriptions;

use Shopware\Core\Content\MailTemplate\Aggregate\MailTemplateType\MailTemplateTypeEntity;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\Language\LanguageEntity;
use Symfony\Component\Finder\Finder;

class OsSubscriptions extends Plugin
{
    public const MAIL_TEMPLATE_CANCEL_SUBSCRIPTION = 'subscription.cancel';

    /**
     * @param InstallContext $installContext
     * @return void
     */
    public function install(InstallContext $installContext): void
    {
        $mailTemplateRepository = $this->container->get('mail_template.repository');
        $mailTemplateTypeRepository = $this->container->get('mail_template_type.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', self::MAIL_TEMPLATE_CANCEL_SUBSCRIPTION));
        $templateType = $mailTemplateTypeRepository->search($criteria, $installContext->getContext())->first();

        if ($templateType instanceof MailTemplateTypeEntity) {
            return;
        }

        $languageRepository = $this->container->get('language.repository');
        $criteria = new Criteria();
        $criteria->addAssociation('locale');
        $criteria->addFilter(new EqualsAnyFilter('locale.code', ['de-AT', 'de-DE']));

        $languages = $languageRepository->search($criteria, $installContext->getContext());
        $emailLanguageSubDirSubscriptionCancellation = 'subscription/cancellation';

        $translations = [];

        /** @var LanguageEntity $language */
        foreach ($languages as $language) {
            $code = $language->getLocale()->getCode();
            $translations[$language->getId()] = [
                'languageId' => $language->getId(),
                'subject' => $this->getMailTemplate('subject', $emailLanguageSubDirSubscriptionCancellation, $code),
                'description' => 'Die E-Mail die an den Kunden gesendet wird, wenn der Kunde sein Abonnement stornieren will.',
                'senderName' => '{{ salesChannel.name }}',
                'contentPlain' => $this->getMailTemplate('plain', $emailLanguageSubDirSubscriptionCancellation, $code),
                'contentHtml' => $this->getMailTemplate('html', $emailLanguageSubDirSubscriptionCancellation, $code),
            ];
        }

        if (!key_exists(Defaults::LANGUAGE_SYSTEM, $translations)) {
            $translations[Defaults::LANGUAGE_SYSTEM] = current($translations);
        }

        $mailTemplateRepository->create([
            [
                'systemDefault' => false,
                'translations' => $translations,
                'mailTemplateType' => [
                    'technicalName' => self::MAIL_TEMPLATE_CANCEL_SUBSCRIPTION,
                    'availableEntities' => [
                        'salesChannel' => 'salesChannel',
                        'customer' => 'customer',
                        'order' => 'order',
                    ],
                    # 'templateData' => [],
                    'translations' => [
                        [
                            'languageId' => Defaults::LANGUAGE_SYSTEM,
                            'name' => 'NIKO DOES NOT KNOW WHAT THIS FIELD IS FOR - IF U KNO IT, PLS TELL HIM!', # TODO: ....
                        ],
                    ],
                ],
            ],
        ], $installContext->getContext());
    }

    /**
     * @param UninstallContext $uninstallContext
     * @return void
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        if ($uninstallContext->keepUserData()) {
            return;
        }

        $mailTemplateRepository = $this->container->get('mail_template.repository');
        $mailTemplateTypeRepository = $this->container->get('mail_template_type.repository');

        $criteria = new Criteria();
        $criteria->addAssociation('mailTemplateType');
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', self::MAIL_TEMPLATE_CANCEL_SUBSCRIPTION));
        $templates = $mailTemplateRepository->search($criteria, $uninstallContext->getContext());

        if ($templates->count() <= 0) {
            return;
        }

        $mailTemplateIds = [];
        $mailTemplateTypeIds = [];

        /** @var MailTemplateEntity $mailTemplate */
        foreach ($templates->getElements() as $mailTemplate) {
            $mailTemplateIds[] = ['id' => $mailTemplate->getId()];

            if (!in_array($mailTemplate->getMailTemplateTypeId(), $mailTemplateTypeIds)) {
                $mailTemplateTypeIds[] = ['id' => $mailTemplate->getMailTemplateTypeId()];
            }
        }

        if (!empty($mailTemplateIds)) {
            $mailTemplateRepository->delete($mailTemplateIds, $uninstallContext->getContext());
        }

        if (!empty($mailTemplateTypeIds)) {
            $mailTemplateTypeRepository->delete($mailTemplateTypeIds, $uninstallContext->getContext());
        }

        parent::uninstall($uninstallContext);
    }

    /**
     * @return int
     */
    public function getTemplatePriority(): int
    {
        # as we don't want to modify the theme.json with template priority
        # we will assume that all other plugins have a lower priority than this one,
        # as modification to customer dashboard are depending on MolliePayments,
        # which has to be loaded beforehand, so we can inherit from MolliePayments.
        return 999;
    }

    /**
     * @param string $filename
     * @param string|null $subDir
     * @param string|null $languageCode
     * @return string|null
     */
    private function getMailTemplate(string $filename, ?string $subDir = null, ?string $languageCode = null): ?string
    {
        $finder = new Finder();

        $languageCode = $languageCode ?? 'de-AT';
        $finder->files()->in(__DIR__ . "/Resources/views/email/{$languageCode}/{$subDir}");

        foreach ($finder as $file) {
            if ($filename === $file->getFilenameWithoutExtension()) {
                return $file->getContents();
            }
        }

        return null;
    }
}