<?php
/**
 * Widget markup for taxonomy based content types
 */

use Smartling\Helpers\StringHelper;
use Smartling\Settings\TargetLocale;
use Smartling\Submissions\SubmissionEntity;
use Smartling\WP\Controller\TaxonomyWidgetController;
use Smartling\WP\WPAbstract;

?>
<style>
    tr.form-field td.sm_sh {
        padding-top: 3px;
        padding-bottom: 4px;
    }

    span.percent-completed {
        left: -1px;
        position: absolute;
        bottom: -2px;
    }
</style>
<?php
/**
 * @var WPAbstract $this
 * @var WPAbstract self
 */
$data = $this->getViewData();


/**
 * @var TargetLocale[] $locales
 */
$locales = $data['profile']->getTargetLocales();

$filteredLocales = [];

foreach ($locales as $locale) {
    /**
     * @var TargetLocale $locale
     */
    if (!$locale->isEnabled() || StringHelper::isNullOrEmpty($locale->getLabel())) {
        continue;
    }

    $filteredLocales[] = $locale;

}

$locales = $filteredLocales;
?>

<?php
if (!empty($locales)) {
    $widgetTitle = 'Download translation';
    ?>

    <div id="smartling-post-widget">
    <h2>Smartling connector actions</h2>

    <h3><?= __($widgetTitle); ?></h3>
    <?= WPAbstract::checkUncheckBlock(); ?>
    <div style="width: 400px;">
        <?php
        $nameKey = TaxonomyWidgetController::WIDGET_DATA_NAME;
        \Smartling\Helpers\ArrayHelper::sortLocales($locales);
        ?>
        <div class="locale-list"> <?php

            foreach ($locales as $locale) {
                /**
                 * @var TargetLocale $locale
                 */
                if (!$locale->isEnabled()) {
                    continue;
                }

                $value = false;
                $enabled = false;
                $status = '';
                $submission = null;
                $statusValue = null;
                $id = null;
                $editUrl = '';
                $statusFlags = [];
                if (null !== $data['submissions']) {
                    foreach ($data['submissions'] as $item) {

                        /**
                         * @var SubmissionEntity $item
                         */
                        if ($item->getTargetBlogId() === $locale->getBlogId() && SubmissionEntity::SUBMISSION_STATUS_CANCELLED !== $item->getStatus()) {
                            $value = true;
                            $statusValue = $item->getStatus();
                            $id = $item->getId();
                            $percent = $item->getCompletionPercentage();
                            $status = $item->getStatusColor();
                            $statusFlags = $item->getStatusFlags();
                            $editUrl = \Smartling\Helpers\WordpressContentTypeHelper::getEditUrl($item);
                            $enabled = 1 === $item->getIsCloned() || 1 === $item->getIsLocked() ? false : true;
                            break;
                        }
                    }
                }
                ?>
                <div class="smtPostWidget-rowWrapper" style="display: inline-block; width: 100%;">
                    <div class="smtPostWidget-row">
                        <?= WPAbstract::localeSelectionCheckboxBlock(
                            $nameKey,
                            $locale->getBlogId(),
                            $locale->getLabel(),
                            (false === $enabled ? false : $value),
                            $enabled,
                            $editUrl
                        ) ?>
                    </div>
                    <div class="smtPostWidget-progress" style="left: 15px;">
                        <?php if ($value) { ?>
                            <?= WPAbstract::localeSelectionTranslationStatusBlock(__($statusValue), $status, $percent, $statusFlags); ?>
                            <?= WPAbstract::inputHidden($id); ?>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
    <?= WPAbstract::submitBlock(); ?>
    </div><?php
} else {
    ?>
    <div id="smartling-post-widget">
        No suitable target locales found.<br/>
        Please check your
        <a href="<?= get_site_url(); ?>/wp-admin/network/admin.php?page=smartling_configuration_profile_setup&action=edit&profile=<?= $data['profile']->getId(); ?>">settings.</a>
    </div>
<?php } ?>
