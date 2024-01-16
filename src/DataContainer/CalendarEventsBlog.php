<?php

declare(strict_types=1);

/*
 * This file is part of SAC Event Blog Bundle.
 *
 * (c) Marko Cupic 2024 <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-event-blog-bundle
 */

namespace Markocupic\SacEventBlogBundle\DataContainer;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Environment;
use Contao\Files;
use Contao\FilesModel;
use Contao\Folder;
use Contao\Image;
use Contao\MemberModel;
use Contao\StringUtil;
use Contao\UserModel;
use Doctrine\DBAL\Connection;
use Markocupic\PhpOffice\PhpWord\MsWordTemplateProcessor;
use Markocupic\SacEventBlogBundle\Config\PublishState;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;
use Markocupic\SacEventToolBundle\CalendarEventsHelper;
use Markocupic\SacEventToolBundle\Config\EventExecutionState;
use Markocupic\SacEventToolBundle\Download\BinaryFileDownload;
use Markocupic\ZipBundle\Zip\Zip;
use PhpOffice\PhpWord\Exception\CopyFileException;
use PhpOffice\PhpWord\Exception\CreateTemporaryFileException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

class CalendarEventsBlog
{
    public function __construct(
        private readonly Security $security,
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly BinaryFileDownload $binaryFileDownload,
        private readonly string $projectDir,
        private readonly string $tempDir,
        private readonly string $eventBlogDocxExportTemplate,
        private readonly string $locale,
    ) {
    }

    /**
     * @throws \Exception
     */
    #[AsCallback(table: 'tl_calendar_events_blog', target: 'config.onload')]
    public function route(): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $id = $request->query->get('id');

        if ($id && 'exportBlog' === $request->query->get('action')) {
            if (null !== ($objBlog = CalendarEventsBlogModel::findByPk($id))) {
                $this->exportBlog($objBlog);
            }
        }
    }

    #[AsCallback(table: 'tl_calendar_events_blog', target: 'config.onload')]
    public function setPalettes(): void
    {
        $user = $this->security->getUser();

        // Overwrite readonly attribute for admins
        if ($user->admin) {
            $fields = ['sacMemberId', 'eventId', 'authorName'];

            foreach ($fields as $field) {
                $GLOBALS['TL_DCA']['tl_calendar_events_blog']['fields'][$field]['eval']['readonly'] = false;
            }
        }
    }

    /**
     * @throws \Exception
     */
    #[AsCallback(table: 'tl_calendar_events_blog', target: 'config.onload')]
    public function deleteUnfinishedAndOldEntries(): void
    {
        // Delete old and unpublished blogs
        $limit = time() - 60 * 60 * 24 * 30;

        $this->connection->executeStatement(
            'DELETE FROM tl_calendar_events_blog WHERE tstamp < ? AND publishState < ?',
            [$limit, PublishState::PUBLISHED],
        );

        // Delete unfinished blogs older the 14 days
        $limit = time() - 60 * 60 * 24 * 14;

        $this->connection->executeStatement(
            'DELETE FROM tl_calendar_events_blog WHERE tstamp < ? AND text = ? AND youTubeId = ? AND multiSRC = ?',
            [$limit, '', '', null]
        );

        // Keep blogs up to date, if events are renamed f.ex.
        $stmt = $this->connection->executeQuery('SELECT * FROM tl_calendar_events_blog', []);

        while (false !== ($arrBlog = $stmt->fetchAssociative())) {
            $objBlogModel = CalendarEventsBlogModel::findByPk($arrBlog['id']);
            $objEvent = $objBlogModel->getRelated('eventId');

            if (null !== $objEvent) {
                $objBlogModel->eventTitle = $objEvent->title;
                $objBlogModel->substitutionEvent = EventExecutionState::STATE_ADAPTED === $objEvent->executionState && '' !== $objEvent->eventSubstitutionText ? $objEvent->eventSubstitutionText : '';
                $objBlogModel->eventStartDate = $objEvent->startDate;
                $objBlogModel->eventEndDate = $objEvent->endDate;
                $objBlogModel->organizers = $objEvent->organizers;

                $aDates = [];
                $arrDates = StringUtil::deserialize($objEvent->eventDates, true);

                foreach ($arrDates as $arrDate) {
                    $aDates[] = $arrDate['new_repeat'];
                }

                $objBlogModel->eventDates = serialize($aDates);
                $objBlogModel->save();
            }
        }
    }

    /**
     * Add an image to each record.
     */
    #[AsCallback(table: 'tl_calendar_events_blog', target: 'list.label.label')]
    public function addIcon(array $row, string $label, DataContainer $dc, array $args): array
    {
        $image = 'member';
        $disabled = false;

        if (PublishState::PUBLISHED !== (int) $row['publishState']) {
            $image .= '_';
            $disabled = true;
        }

        $args[0] = sprintf(
            '<div class="list_icon_new" style="background-image:url(\'%s\')" data-icon="%s" data-icon-disabled="%s">&nbsp;</div>',
            Image::getPath($image),
            Image::getPath($disabled ? $image : rtrim($image, '_')),
            Image::getPath(rtrim($image, '_').'_')
        );

        return $args;
    }

    /**
     * @throws CopyFileException
     * @throws CreateTemporaryFileException
     */
    private function exportBlog(CalendarEventsBlogModel $objBlog): void
    {
        $objEvent = CalendarEventsModel::findByPk($objBlog->eventId);

        if (null === $objEvent) {
            throw new \Exception('Event not found.');
        }

        if (!is_file($this->projectDir.'/'.$this->eventBlogDocxExportTemplate)) {
            throw new \Exception('Template file not found.');
        }

        // target dir & file
        $targetDir = sprintf('system/tmp/blog_%s_%s', $objBlog->id, time());
        $imageDir = sprintf('%s/images', $targetDir);

        // Create folder
        new Folder($imageDir);

        $targetFile = sprintf('%s/event_blog_%s.docx', $targetDir, $objBlog->id);
        $objPhpWord = new MsWordTemplateProcessor($this->eventBlogDocxExportTemplate, $targetFile);

        // Organizers
        $arrOrganizers = CalendarEventsHelper::getEventOrganizersAsArray($objEvent);
        $strOrganizers = implode(', ', $arrOrganizers);

        // Instructors
        $mainInstructorName = CalendarEventsHelper::getMainInstructorName($objEvent);
        $mainInstructorEmail = '';

        if (null !== ($objInstructor = UserModel::findByPk($objEvent->mainInstructor))) {
            $mainInstructorEmail = $objInstructor->email;
        }

        $objMember = MemberModel::findBySacMemberId($objBlog->sacMemberId);
        $strAuthorEmail = '';

        if (null !== $objMember) {
            $strAuthorEmail = $objMember->email;
        }

        // Event dates
        $arrEventDates = CalendarEventsHelper::getEventTimestamps($objEvent);
        $arrEventDates = array_map(
            static fn ($tstamp) => date('Y-m-d', (int) $tstamp),
            $arrEventDates
        );
        $strEventDates = implode("\r\n", $arrEventDates);

        // Checked by instructor
        $strCheckedByInstructor = $objBlog->checkedByInstructor ? 'Ja' : 'Nein';

        // Backend url
        $strUrlBackend = sprintf(
            '%s/contao?do=sac_calendar_events_blog_tool&act=edit&id=%s',
            Environment::get('url'),
            $objBlog->id
        );

        // Key data
        $arrKeyData = [];

        if (!empty($objBlog->tourTechDifficulty)) {
            $arrKeyData[] = $objBlog->tourTechDifficulty;
        }

        if (!empty($objBlog->tourProfile)) {
            $arrKeyData[] = $objBlog->tourProfile;
        }

        // tourTypes
        $arrTourTypes = CalendarEventsHelper::getTourTypesAsArray($objEvent, 'title');

        $options = ['multiline' => true];
        $objPhpWord->replace('checkedByInstructor', $strCheckedByInstructor, $options);
        $objPhpWord->replace('title', $objBlog->title, $options);
        $objPhpWord->replace('text', $objBlog->text, $options);
        $objPhpWord->replace('authorName', $objBlog->authorName, $options);
        $objPhpWord->replace('sacMemberId', $objBlog->sacMemberId, $options);
        $objPhpWord->replace('authorEmail', $strAuthorEmail, $options);
        $objPhpWord->replace('dateAdded', date('Y-m-d', (int) $objBlog->dateAdded), $options);
        $objPhpWord->replace('tourTypes', implode(', ', $arrTourTypes), $options);
        $objPhpWord->replace('organizers', $strOrganizers, $options);
        $objPhpWord->replace('mainInstructorName', $mainInstructorName, $options);
        $objPhpWord->replace('mainInstructorEmail', $mainInstructorEmail, $options);
        $objPhpWord->replace('eventDates', $strEventDates, $options);
        $objPhpWord->replace('tourWaypoints', $objBlog->tourWaypoints, $options);
        $objPhpWord->replace('keyData', implode("\r\n", $arrKeyData), $options);
        $objPhpWord->replace('tourHighlights', $objBlog->tourHighlights, $options);
        $objPhpWord->replace('tourPublicTransportInfo', $objBlog->tourPublicTransportInfo, $options);

        // Footer
        $objPhpWord->replace('eventId', $objEvent->id);
        $objPhpWord->replace('blogId', $objBlog->id);
        $objPhpWord->replace('urlBackend', htmlentities($strUrlBackend));

        // Images
        if (!empty($objBlog->multiSRC)) {
            $arrImages = StringUtil::deserialize($objBlog->multiSRC, true);

            if (!empty($arrImages)) {
                $objFiles = FilesModel::findMultipleByUuids($arrImages);
                $i = 0;

                while ($objFiles->next()) {
                    if (!is_file($this->projectDir.'/'.$objFiles->path)) {
                        continue;
                    }

                    ++$i;

                    Files::getInstance()->copy($objFiles->path, $imageDir.'/'.$objFiles->name);

                    $options = ['multiline' => false];

                    $objPhpWord->createClone('i');
                    $objPhpWord->addToClone('i', 'i', $i, $options);
                    $objPhpWord->addToClone('i', 'fileName', $objFiles->name, $options);

                    $arrMeta = $this->getMeta($objFiles->current(), $this->locale);
                    $objPhpWord->addToClone('i', 'photographerName', $arrMeta['photographer'], $options);
                    $objPhpWord->addToClone('i', 'imageCaption', $arrMeta['caption'], $options);
                }
            }
        }

        $zipSrc = sprintf(
            '%s/%s/blog_%s_%s.zip',
            $this->projectDir,
            $this->tempDir,
            $objBlog->id,
            time()
        );

        // Generate docx and save it in system/tmp/...
        $objPhpWord->sendToBrowser(false)
            ->generateUncached(true)
            ->generate()
        ;

        // Zip archive
        (new Zip())
            ->ignoreDotFiles(false)
            ->stripSourcePath($this->projectDir.'/'.$targetDir)
            ->addDirRecursive($this->projectDir.'/'.$targetDir)
            ->run($zipSrc)
        ;

        $this->binaryFileDownload->sendFileToBrowser($zipSrc, basename($zipSrc));
    }

    private function getMeta(FilesModel $objFile, string $lang = 'en'): array
    {
        $arrMeta = StringUtil::deserialize($objFile->meta, true);

        if (!isset($arrMeta[$lang]['caption'])) {
            $arrMeta[$lang]['caption'] = '';
        }

        if (!isset($arrMeta[$lang]['photographer'])) {
            $arrMeta[$lang]['photographer'] = '';
        }

        return $arrMeta[$lang];
    }
}
