<?php

declare(strict_types=1);

/*
 * This file is part of SAC Event Tool Bundle.
 *
 * (c) Marko Cupic 2022 <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-event-tool-bundle
 */

namespace Markocupic\SacEventBlogBundle\Controller\FrontendModule;

use Contao\CalendarEventsMemberModel;
use Contao\CalendarEventsModel;
use Contao\Config;
use Contao\Controller;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\CoreBundle\ServiceAnnotation\FrontendModule;
use Contao\Dbafs;
use Contao\Environment;
use Contao\File;
use Contao\Files;
use Contao\FilesModel;
use Contao\Folder;
use Contao\FrontendUser;
use Contao\Input;
use Contao\Message;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Contao\Template;
use Contao\Validator;
use Doctrine\DBAL\Connection;
use Haste\Form\Form;
use Haste\Util\Url;
use Markocupic\SacEventBlogBundle\Model\CalendarEventsBlogModel;
use Markocupic\SacEventToolBundle\CalendarEventsHelper;
use Markocupic\SacEventToolBundle\Config\EventExecutionState;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @FrontendModule(MemberDashboardEventBlogWriteController::TYPE, category="sac_event_tool_frontend_modules")
 */
class MemberDashboardEventBlogWriteController extends AbstractFrontendModuleController
{
    public const TYPE = 'member_dashboard_write_event_blog';
    private ContaoFramework $framework;
    private Connection $connection;
    private RequestStack $requestStack;
    private TranslatorInterface $translator;
    private Security $security;
    private string $projectDir;
    private string $eventBlogAssetDir;
    private string $locale;

    private FrontendUser|null $user;
    private PageModel|null $page;

    public function __construct(ContaoFramework $framework, Connection $connection, RequestStack $requestStack, TranslatorInterface $translator, Security $security, string $projectDir, string $eventBlogAssetDir, string $locale)
    {
        $this->framework = $framework;
        $this->connection = $connection;
        $this->requestStack = $requestStack;
        $this->translator = $translator;
        $this->security = $security;
        $this->projectDir = $projectDir;
        $this->eventBlogAssetDir = $eventBlogAssetDir;
        $this->locale = $locale;

        // Get logged in member object
        if (($user = $this->security->getUser()) instanceof FrontendUser) {
            $this->user = $user;
        }

    }

    public function __invoke(Request $request, ModuleModel $model, string $section, array $classes = null, PageModel $page = null): Response
    {


        if (null !== $page) {
            // Neither cache nor search page
            $page->noSearch = 1;
            $page->cache = 0;
            $page->clientCache = 0;

            // Set the page object
            $this->page = $page;
        }

        // Call the parent method
        return parent::__invoke($request, $model, $section, $classes);
    }

    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices();
    }

    /**
     * @throws \Exception
     */
    protected function getResponse(Template $template, ModuleModel $model, Request $request): Response|null
    {
        // Do not allow for not authorized users
        if (null === $this->user) {
            throw new UnauthorizedHttpException('Not authorized. Please log in as frontend user.');
        }

        // Set adapters
        $messageAdapter = $this->framework->getAdapter(Message::class);
        $validatorAdapter = $this->framework->getAdapter(Validator::class);
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);
        $calendarEventsMemberModelAdapter = $this->framework->getAdapter(CalendarEventsMemberModel::class);
        $calendarEventsBlogModelAdapter = $this->framework->getAdapter(CalendarEventsBlogModel::class);
        $calendarEventsHelperAdapter = $this->framework->getAdapter(CalendarEventsHelper::class);
        $controllerAdapter = $this->framework->getAdapter(Controller::class);
        $inputAdapter = $this->framework->getAdapter(Input::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        // Load language file
        $controllerAdapter->loadLanguageFile('tl_calendar_events_blog');

        // Handle messages
        if (empty($this->user->email) || !$validatorAdapter->isEmail($this->user->email)) {
            $messageAdapter->addInfo($this->translator->trans('ERR.md_write_event_blog_emailAddressNotFound', [], 'contao_default'));
        }

        $objEvent = $calendarEventsModelAdapter->findByPk($inputAdapter->get('eventId'));

        if (null === $objEvent) {
            $messageAdapter->addError($this->translator->trans('ERR.md_write_event_blog_eventNotFound', [$inputAdapter->get('eventId')], 'contao_default'));
        }

        if (!$messageAdapter->hasError()) {
            // Check if report already exists
            $objReportModel = CalendarEventsBlogModel::findOneBySacMemberIdAndEventId($this->user->sacMemberId, $objEvent->id);

            if (null === $objReportModel) {
                if ($objEvent->endDate + $model->eventBlogTimeSpanForCreatingNew * 24 * 60 * 60 < time()) {
                    // Do not allow blogging for old events
                    $messageAdapter->addError($this->translator->trans('ERR.md_write_event_blog_createBlogDeadlineExpired', [], 'contao_default'));
                }

                if (!$messageAdapter->hasError()) {
                    $blnAllow = false;
                    $intStartDateMin = $model->eventBlogTimeSpanForCreatingNew > 0 ? time() - $model->eventBlogTimeSpanForCreatingNew * 24 * 3600 : time();
                    $arrAllowedEvents = $calendarEventsMemberModelAdapter->findEventsByMemberId($this->user->id, [], $intStartDateMin, time(), true);

                    foreach ($arrAllowedEvents as $allowedEvent) {
                        if ((int) $allowedEvent['id'] === (int) $inputAdapter->get('eventId')) {
                            $blnAllow = true;
                        }
                    }

                    // User has not participated on the event neither as guide nor as participant and is not allowed to write a report
                    if (!$blnAllow) {
                        $messageAdapter->addError($this->translator->trans('ERR.md_write_event_blog_writingPermissionDenied', [], 'contao_default'));
                    }
                }
            }

            if (!$messageAdapter->hasError()) {
                if (null === $objReportModel) {
                    // Create new
                    $aDates = [];
                    $arrDates = $stringUtilAdapter->deserialize($objEvent->eventDates, true);

                    foreach ($arrDates as $arrDate) {
                        $aDates[] = $arrDate['new_repeat'];
                    }

                    $set = [
                        'title' => $objEvent->title,
                        'eventTitle' => $objEvent->title,
                        'eventSubstitutionText' => EventExecutionState::STATE_ADAPTED === $objEvent->executionState && '' !== $objEvent->eventSubstitutionText ? $objEvent->eventSubstitutionText : '',
                        'eventStartDate' => $objEvent->startDate,
                        'eventEndDate' => $objEvent->endDate,
                        'organizers' => $objEvent->organizers,
                        'eventDates' => serialize($aDates),
                        'authorName' => $this->user->firstname.' '.$this->user->lastname,
                        'sacMemberId' => $this->user->sacMemberId,
                        'eventId' => $inputAdapter->get('eventId'),
                        'tstamp' => time(),
                        'dateAdded' => time(),
                    ];

                    $affected = $this->connection->insert('tl_calendar_events_blog',$set);

                    // Set security token for frontend preview
                    if ($affected) {
                        $insertId = $this->connection->lastInsertId();
                        $set = [
                            'securityToken' => md5((string) random_int(100000000, 999999999)).$insertId,
                            ];

                        $this->connection->update('tl_calendar_events_blog', $set, ['id' => $insertId]);

                        $objReportModel = $calendarEventsBlogModelAdapter->findByPk($insertId);
                    }
                }

                if (!isset($objReportModel)) {
                    throw new \Exception('Event report model not found.');
                }

                $template->eventName = $objEvent->title;
                $template->executionState = $objEvent->executionState;
                $template->eventSubstitutionText = $objEvent->eventSubstitutionText;
                $template->youtubeId = $objReportModel->youtubeId;
                $template->text = $objReportModel->text;
                $template->title = $objReportModel->title;
                $template->publishState = $objReportModel->publishState;
                $template->eventPeriod = $calendarEventsHelperAdapter->getEventPeriod($objEvent);

                // Get the gallery
                $template->images = $this->getGalleryImages($objReportModel);

                if ('' !== $objReportModel->tourWaypoints) {
                    $template->tourWaypoints = nl2br((string) $objReportModel->tourWaypoints);
                }

                if ('' !== $objReportModel->tourProfile) {
                    $template->tourProfile = nl2br((string) $objReportModel->tourProfile);
                }

                if ('' !== $objReportModel->tourTechDifficulty) {
                    $template->tourTechDifficulty = nl2br((string) $objReportModel->tourTechDifficulty);
                }

                if ('' !== $objReportModel->tourHighlights) {
                    $template->tourHighlights = nl2br((string) $objReportModel->tourHighlights);
                }

                if ('' !== $objReportModel->tourPublicTransportInfo) {
                    $template->tourPublicTransportInfo = nl2br((string) $objReportModel->tourPublicTransportInfo);
                }

                // Generate forms
                $template->objEventBlogTextAndYoutubeForm = $this->generateTextAndYoutubeForm($objReportModel);
                $template->objEventBlogImageUploadForm = $this->generatePictureUploadForm($objReportModel, $model);

                // Get the preview link
                $template->previewLink = $this->getPreviewLink($objReportModel, $model);
            }
        }

        // Check if all images are labeled with a legend and a photographer name
        if (isset($objReportModel) && $objReportModel->publishState < 2) {
            if (!$this->validateImageUploads($objReportModel)) {
                $messageAdapter->addInfo($this->translator->trans('ERR.md_write_event_blog_missingImageLegend', [], 'contao_default'));
            }
        }

        // Add messages to template
        $this->addMessagesToTemplate($template);

        return $template->getResponse();
    }

    protected function validateImageUploads(CalendarEventsBlogModel $objReportModel): bool
    {
        /** @var StringUtil $stringUtilAdapter */
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        /** @var FilesModel $filesModelAdapter */
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);

        // Check for a valid photographer name an exiting image legends
        if (!empty($objReportModel->multiSRC) && !empty($stringUtilAdapter->deserialize($objReportModel->multiSRC, true))) {
            $arrUuids = $stringUtilAdapter->deserialize($objReportModel->multiSRC, true);
            $objFiles = $filesModelAdapter->findMultipleByUuids($arrUuids);
            $blnMissingLegend = false;
            $blnMissingPhotographerName = false;

            while ($objFiles->next()) {
                $arrMeta = $stringUtilAdapter->deserialize($objFiles->meta, true);

                if (!isset($arrMeta[$this->locale]['caption']) || '' === $arrMeta[$this->locale]['caption']) {
                    $blnMissingLegend = true;
                }

                if (!isset($arrMeta[$this->locale]['photographer']) || '' === $arrMeta[$this->locale]['photographer']) {
                    $blnMissingPhotographerName = true;
                }
            }

            if ($blnMissingLegend || $blnMissingPhotographerName) {
                return false;
            }
        }

        return true;
    }

    /**
     * Add messages from session to template.
     */
    protected function addMessagesToTemplate(Template $template): void
    {
        // Set adapters
        $messageAdapter = $this->framework->getAdapter(Message::class);

        if ($messageAdapter->hasInfo()) {
            $template->hasInfoMessage = true;
            $session = $this->requestStack->getCurrentRequest()->getSession()->getFlashBag()->get('contao.FE.info');
            $template->infoMessage = $session[0];
        }

        if ($messageAdapter->hasError()) {
            $template->hasErrorMessage = true;
            $session = $this->requestStack->getCurrentRequest()->getSession()->getFlashBag()->get('contao.FE.error');
            $template->errorMessage = $session[0];
            $template->errorMessages = $session;
        }

        $messageAdapter->reset();
    }

    protected function generateTextAndYoutubeForm(CalendarEventsBlogModel $objEventBlogModel): string
    {
        // Set adapters
        $environmentAdapter = $this->framework->getAdapter(Environment::class);
        $controllerAdapter = $this->framework->getAdapter(Controller::class);
        $inputAdapter = $this->framework->getAdapter(Input::class);

        $objForm = new Form(
            'form-event-blog-text-and-youtube',
            'POST',
            function ($objHaste) {
                /** @var Input $inputAdapter */
                $inputAdapter = $this->framework->getAdapter(Input::class);

                return $inputAdapter->post('FORM_SUBMIT') === $objHaste->getFormId();
            }
        );

        $url = $environmentAdapter->get('uri');
        $objForm->setFormActionFromUri($url);

        // Title
        $objForm->addFormField('title', [
            'label' => 'Tourname/Tourtitel',
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'decodeEntities' => true],
            'value' => $this->getTourTitle($objEventBlogModel),
        ]);

        // text
        $maxlength = 1700;
        $objForm->addFormField('text', [
            'label' => 'Touren-/Lager-/Kursbericht (max. '.$maxlength.' Zeichen, inkl. Leerzeichen)',
            'inputType' => 'textarea',
            'eval' => ['mandatory' => true, 'maxlength' => $maxlength, 'rows' => 8, 'decodeEntities' => true],
            'value' => (string) $objEventBlogModel->text,
        ]);

        // tour waypoints
        $eval = ['mandatory' => true, 'maxlength' => 300, 'rows' => 2, 'decodeEntities' => true, 'placeholder' => 'z.B. Engelberg 1000m - Herrenrüti 1083 m - Galtiberg 1800 m - Einstieg 2000 m'];

        $objForm->addFormField(
            'tourWaypoints',
            [
                'label' => 'Tourenstationen mit Höhenangaben (nur stichwortartig)',
                'inputType' => 'textarea',
                'eval' => $eval,
                'value' => $this->getTourWaypoints($objEventBlogModel),
            ]
        );

        // tour profile
        $eval = ['mandatory' => true, 'rows' => 2, 'decodeEntities' => true, 'placeholder' => 'z.B. Aufst: 1500 Hm/8 h, Abst: 1500 Hm/3 h'];

        $objForm->addFormField(
            'tourProfile',
            [
                'label' => 'Höhenmeter und Zeitangabe pro Tag',
                'inputType' => 'textarea',
                'eval' => $eval,
                'value' => $this->getTourProfile($objEventBlogModel),
            ]
        );

        // tour difficulties
        $eval = ['mandatory' => true, 'rows' => 2, 'decodeEntities' => true];

        $objForm->addFormField('tourTechDifficulty', [
            'label' => 'Technische Schwierigkeiten',
            'inputType' => 'textarea',
            'eval' => $eval,
            'value' => $this->getTourTechDifficulties($objEventBlogModel),
        ]);

        // tour highlights (not mandatory)
        $eval = ['mandatory' => true, 'class' => 'publish-clubmagazine-field', 'rows' => 2, 'decodeEntities' => true];

        $objForm->addFormField('tourHighlights', [
            'label' => 'Highlights/Bemerkungen (max. 3 Sätze)',
            'inputType' => 'textarea',
            'eval' => $eval,
            'value' => (string) $objEventBlogModel->tourHighlights,
        ]);

        // tour public transport info
        $eval = ['mandatory' => false, 'class' => 'publish-clubmagazine-field', 'rows' => 2, 'decodeEntities' => true];

        $objForm->addFormField('tourPublicTransportInfo', [
            'label' => 'Mögliche ÖV-Verbindung',
            'inputType' => 'textarea',
            'eval' => $eval,
            'value' => (string) $objEventBlogModel->tourPublicTransportInfo,
        ]);

        // youtube id
        $objForm->addFormField(
            'youtubeId',
            [
                'label' => 'Youtube Film-Id',
                'inputType' => 'text',
                'eval' => ['placeholder' => 'z.B. G02hYgT3nGw'],
                'value' => (string) $objEventBlogModel->youtubeId,
            ]
        );

        // Let's add  a submit button
        $objForm->addFormField('submitEventReportTextFormBtn', [
            'label' => 'Änderungen speichern',
            'inputType' => 'submit',
        ]);

        // Bind model
        $objForm->bindModel($objEventBlogModel);

        // validate() also checks whether the form has been submitted
        if ($objForm->validate() && $inputAdapter->post('FORM_SUBMIT') === $objForm->getFormId()) {
            $objEventBlogModel->dateAdded = time();
            $objEventBlogModel->title = html_entity_decode((string) $objForm->getWidget('title')->value);
            $objEventBlogModel->text = html_entity_decode((string) $objForm->getWidget('text')->value);
            $objEventBlogModel->youtubeId = $objForm->getWidget('youtubeId')->value;
            $objEventBlogModel->tourWaypoints = html_entity_decode((string) $objForm->getWidget('tourWaypoints')->value);
            $objEventBlogModel->tourProfile = html_entity_decode((string) $objForm->getWidget('tourProfile')->value);
            $objEventBlogModel->tourTechDifficulty = html_entity_decode((string) $objForm->getWidget('tourTechDifficulty')->value);
            $objEventBlogModel->tourHighlights = html_entity_decode((string) $objForm->getWidget('tourHighlights')->value);
            $objEventBlogModel->tourPublicTransportInfo = html_entity_decode((string) $objForm->getWidget('tourPublicTransportInfo')->value);

            $objEventBlogModel->save();

            $hasErrors = false;

            // Check mandatory fields
            if ('' === $objForm->getWidget('text')->value) {
                $objForm->getWidget('text')->addError($this->translator->trans('ERR.md_write_event_blog_writeSomethingAboutTheEvent', [], 'contao_default'));
                $hasErrors = true;
            }

            // Reload page
            if (!$hasErrors) {
                $controllerAdapter->reload();
            }
        }

        // Add some Vue.js attributes to the form widgets
        $this->addVueAttributesToFormWidget($objForm);

        return $objForm->generate();
    }

    protected function addVueAttributesToFormWidget(Form $objForm): void
    {
        $objForm->getWidget('text')->addAttribute('v-model', 'ctrl_text.value');
        $objForm->getWidget('text')->addAttribute('v-on:keyup', 'onKeyUp("ctrl_text")');
    }

    protected function getTourProfile(CalendarEventsBlogModel $objEventBlogModel): string
    {
        $calendarEventsHelperAdapter = $this->framework->getAdapter(CalendarEventsHelper::class);
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);

        if (!empty($objEventBlogModel->tourProfile)) {
            return $objEventBlogModel->tourProfile;
        }
        $objEvent = $calendarEventsModelAdapter->findByPk($objEventBlogModel->eventId);

        if (null !== $objEvent) {
            $arrData = $calendarEventsHelperAdapter->getTourProfileAsArray($objEvent);

            return implode("\r\n", $arrData);
        }

        return '';
    }

    protected function getTourTitle(CalendarEventsBlogModel $objEventBlogModel): string
    {
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);

        if (!empty($objEventBlogModel->title)) {
            return $objEventBlogModel->title;
        }

        $objEvent = $calendarEventsModelAdapter->findByPk($objEventBlogModel->eventId);

        if (null !== $objEvent) {
            return '' !== $objEvent->title ? $objEvent->title : '';
        }

        return '';
    }

    protected function getTourWaypoints(CalendarEventsBlogModel $objEventBlogModel): string
    {
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);

        if (!empty($objEventBlogModel->tourWaypoints)) {
            return $objEventBlogModel->tourWaypoints;
        }
        $objEvent = $calendarEventsModelAdapter->findByPk($objEventBlogModel->eventId);

        if (null !== $objEvent) {
            return !empty($objEvent->tourDetailText) ? $objEvent->tourDetailText : '';
        }

        return '';
    }

    protected function getTourTechDifficulties(CalendarEventsBlogModel $objEventBlogModel): string
    {
        $calendarEventsHelperAdapter = $this->framework->getAdapter(CalendarEventsHelper::class);
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);

        if (!empty($objEventBlogModel->tourTechDifficulty)) {
            return $objEventBlogModel->tourTechDifficulty;
        }
        $objEvent = $calendarEventsModelAdapter->findByPk($objEventBlogModel->eventId);

        if (null !== $objEvent) {
            $arrData = $calendarEventsHelperAdapter->getTourTechDifficultiesAsArray($objEvent);

            if (empty($arrData)) {
                return $this->translator->trans('ERR.md_write_event_blog_notSpecified', [], 'contao_default');
            }

            return implode("\r\n", $arrData);
        }

        return '';
    }

    /**
     * @throws \Exception
     */
    protected function generatePictureUploadForm(CalendarEventsBlogModel $objEventBlogModel, ModuleModel $moduleModel): string
    {
        // Set adapters
        /** @var Environment $environmentAdapter */
        $environmentAdapter = $this->framework->getAdapter(Environment::class);
        /** @var Controller $controllerAdapter */
        $controllerAdapter = $this->framework->getAdapter(Controller::class);
        /** @var Input $inputAdapter */
        $inputAdapter = $this->framework->getAdapter(Input::class);
        /** @var Validator $validatorAdapter */
        $validatorAdapter = $this->framework->getAdapter(Validator::class);
        /** @var Files $filesAdapter */
        $filesAdapter = $this->framework->getAdapter(Files::class);
        /** @var StringUtil $stringUtilAdapter */
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);
        /** @var FilesModel $filesModelAdapter */
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);
        /** @var Dbafs $dbafsAdapter */
        $dbafsAdapter = $this->framework->getAdapter(Dbafs::class);
        /** @var Config $configAdapter */
        $configAdapter = $this->framework->getAdapter(Config::class);

        // Set max image widht and height
        if ((int) $moduleModel->eventBlogMaxImageWidth > 0) {
            $configAdapter->set('imageWidth', (int) $moduleModel->eventBlogMaxImageWidth);
        }

        if ((int) $moduleModel->eventBlogMaxImageHeight > 0) {
            $configAdapter->set('imageHeight', (int) $moduleModel->eventBlogMaxImageHeight);
        }

        $objUploadFolder = new Folder($this->eventBlogAssetDir.'/'.$objEventBlogModel->id);
        $dbafsAdapter->addResource($objUploadFolder->path);

        if (!is_dir($this->projectDir.'/'.$this->eventBlogAssetDir.'/'.$objEventBlogModel->id)) {
            throw new \Exception($this->translator->trans('ERR.md_write_event_blog_uploadDirNotFound', [], 'contao_default'));
        }

        $objForm = new Form(
            'form-event-blog-picture-upload',
            'POST',
            function ($objHaste) {
                /** @var Input $inputAdapter */
                $inputAdapter = $this->framework->getAdapter(Input::class);

                return $inputAdapter->post('FORM_SUBMIT') === $objHaste->getFormId();
            }
        );

        $url = $environmentAdapter->get('uri');
        $objForm->setFormActionFromUri($url);

        // Add some fields
        $objForm->addFormField('fileupload', [
            'label' => 'Bildupload',
            'inputType' => 'fineUploader',
            'eval' => ['extensions' => 'jpg,jpeg',
                'storeFile' => true,
                'addToDbafs' => true,
                'isGallery' => false,
                'directUpload' => false,
                'multiple' => true,
                'useHomeDir' => false,
                'uploadFolder' => $objUploadFolder->path,
                'mandatory' => true,
            ],
        ]);

        // Let's add  a submit button
        $objForm->addFormField('submitImageUploadFormBtn', [
            'label' => 'Bildupload starten',
            'inputType' => 'submit',
        ]);

        // Add attributes
        $objWidgetFileupload = $objForm->getWidget('fileupload');
        $objWidgetFileupload->addAttribute('accept', '.jpg, .jpeg');
        $objWidgetFileupload->storeFile = true;

        // validate() also checks whether the form has been submitted
        if ($objForm->validate() && $inputAdapter->post('FORM_SUBMIT') === $objForm->getFormId()) {
            if (!empty($_SESSION['FILES']) && \is_array($_SESSION['FILES'])) {
                foreach ($_SESSION['FILES'] as $file) {
                    $uuid = $file['uuid'];

                    if ($validatorAdapter->isStringUuid($uuid)) {
                        $binUuid = $stringUtilAdapter->uuidToBin($uuid);
                        $objModel = $filesModelAdapter->findByUuid($binUuid);

                        if (null !== $objModel) {
                            $objFile = new File($objModel->path);

                            if ($objFile->isImage) {
                                // Rename file
                                $newFilename = sprintf('event-blog-%s-img-%s.%s', $objEventBlogModel->id, $objModel->id, strtolower($objFile->extension));
                                $newPath = $objUploadFolder->path.'/'.$newFilename;
                                $filesAdapter->getInstance()->rename($objFile->path, $newPath);
                                $objModel->path = $newPath;
                                $objModel->name = basename($newPath);
                                $objModel->tstamp = time();
                                $objModel->save();
                                $dbafsAdapter->updateFolderHashes($objUploadFolder->path);

                                if (is_file($this->projectDir.'/'.$newPath)) {
                                    $oFileModel = $filesModelAdapter->findByPath($newPath);

                                    if (null !== $oFileModel) {
                                        // Add photographer name to meta field
                                        if (null !== $this->user) {
                                            $arrMeta = $stringUtilAdapter->deserialize($oFileModel->meta, true);

                                            if (!isset($arrMeta[$this->page->language])) {
                                                $arrMeta[$this->page->language] = [
                                                    'title' => '',
                                                    'alt' => '',
                                                    'link' => '',
                                                    'caption' => '',
                                                    'photographer' => '',
                                                ];
                                            }
                                            $arrMeta[$this->page->language]['photographer'] = $this->user->firstname.' '.$this->user->lastname;
                                            $oFileModel->meta = serialize($arrMeta);
                                            $oFileModel->save();
                                        }

                                        // Save gallery data to tl_calendar_events_blog
                                        $multiSRC = $stringUtilAdapter->deserialize($objEventBlogModel->multiSRC, true);
                                        $multiSRC[] = $oFileModel->uuid;
                                        $objEventBlogModel->multiSRC = serialize($multiSRC);
                                        $orderSRC = $stringUtilAdapter->deserialize($objEventBlogModel->multiSRC, true);
                                        $orderSRC[] = $oFileModel->uuid;
                                        $objEventBlogModel->orderSRC = serialize($orderSRC);
                                        $objEventBlogModel->save();
                                    }

                                    // Log
                                    $strText = sprintf('User with username %s has uploadad a new picture ("%s").', $this->user->username, $objModel->path);
                                    $logger = System::getContainer()->get('monolog.logger.contao');
                                    $logger->log(LogLevel::INFO, $strText, ['contao' => new ContaoContext(__METHOD__, 'EVENT STORY PICTURE UPLOAD')]);
                                }
                            }
                        }
                    }
                }
            }

            if (!$objWidgetFileupload->hasErrors()) {
                // Reload page
                $controllerAdapter->reload();
            }
        }

        unset($_SESSION['FILES']);

        return $objForm->generate();
    }

    /**
     * @throws \Exception
     */
    protected function getGalleryImages(CalendarEventsBlogModel $objBlog): array
    {
        // Set adapters
        /** @var Validator $validatorAdapter */
        $validatorAdapter = $this->framework->getAdapter(Validator::class);
        /** @var StringUtil $stringUtilAdapter */
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);
        /** @var FilesModel $filesModelAdapter */
        $filesModelAdapter = $this->framework->getAdapter(FilesModel::class);

        $images = [];
        $arrMultiSRC = $stringUtilAdapter->deserialize($objBlog->multiSRC, true);

        foreach ($arrMultiSRC as $uuid) {
            if ($validatorAdapter->isUuid($uuid)) {
                $objFiles = $filesModelAdapter->findByUuid($uuid);

                if (null !== $objFiles) {
                    if (is_file($this->projectDir.'/'.$objFiles->path)) {
                        $objFile = new File($objFiles->path);

                        if ($objFile->isImage) {
                            $arrMeta = $stringUtilAdapter->deserialize($objFiles->meta, true);
                            $images[$objFiles->path] = [
                                'id' => $objFiles->id,
                                'path' => $objFiles->path,
                                'uuid' => $objFiles->uuid,
                                'name' => $objFile->basename,
                                'singleSRC' => $objFiles->path,
                                'title' => $stringUtilAdapter->specialchars($objFile->basename),
                                'filesModel' => $objFiles->current(),
                                'caption' => $arrMeta[$this->locale]['caption'] ?? '',
                                'photographer' => $arrMeta[$this->locale]['photographer'] ?? '',
                                'alt' => $arrMeta[$this->locale]['alt'] ?? '',
                            ];
                        }
                    }
                }
            }
        }

        // Custom image sorting
        if ('' !== $objBlog->orderSRC) {
            $tmp = $stringUtilAdapter->deserialize($objBlog->orderSRC);

            if (!empty($tmp) && \is_array($tmp)) {
                // Remove all values
                $arrOrder = array_map(
                    static function (): void {
                    },
                    array_flip($tmp)
                );

                // Move the matching elements to their position in $arrOrder
                foreach ($images as $k => $v) {
                    if (\array_key_exists($v['uuid'], $arrOrder)) {
                        $arrOrder[$v['uuid']] = $v;
                        unset($images[$k]);
                    }
                }

                // Append the left-over images at the end
                if (!empty($images)) {
                    $arrOrder = array_merge($arrOrder, array_values($images));
                }

                // Remove empty (unreplaced) entries
                $images = array_values(array_filter($arrOrder));
                unset($arrOrder);
            }
        }

        return array_values($images);
    }

    protected function getPreviewLink(CalendarEventsBlogModel $objBlog, ModuleModel $objModule): string
    {
        /** @var PageModel $pageModelAdapter */
        $pageModelAdapter = $this->framework->getAdapter(PageModel::class);

        /** @var Config $configAdapter */
        $configAdapter = $this->framework->getAdapter(Config::class);

        /** @var StringUtil $stringUtilAdapter */
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        /** @var Environment $environmentAdapterAdapter */
        $environmentAdapter = $this->framework->getAdapter(Environment::class);

        /** @var Url $urlAdapter */
        $urlAdapter = $this->framework->getAdapter(Url::class);

        // Generate frontend preview link
        $previewLink = '';

        if ($objModule->eventBlogJumpTo > 0) {
            $objTarget = $pageModelAdapter->findByPk($objModule->eventBlogJumpTo);

            if (null !== $objTarget) {
                $previewLink = $stringUtilAdapter->ampersand($objTarget->getFrontendUrl($configAdapter->get('useAutoItem') ? '/%s' : '/items/%s'));
                $previewLink = sprintf($previewLink, $objBlog->id);
                $previewLink = $environmentAdapter->get('url').'/'.$urlAdapter->addQueryString('securityToken='.$objBlog->securityToken, $previewLink);
            }
        }

        return $previewLink;
    }
}
