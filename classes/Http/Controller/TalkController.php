<?php

declare(strict_types=1);

/**
 * Copyright (c) 2013-2017 OpenCFP
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/opencfp/opencfp
 */

namespace OpenCFP\Http\Controller;

use HTMLPurifier;
use OpenCFP\Domain\CallForPapers;
use OpenCFP\Domain\Model\Talk;
use OpenCFP\Domain\Services\Authentication;
use OpenCFP\Http\Form\TalkForm;
use OpenCFP\Http\View\TalkHelper;
use Swift_Mailer;
use Swift_Message;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig_Environment;

class TalkController extends BaseController
{
    /**
     * @var Authentication
     */
    private $authentication;

    /**
     * @var TalkHelper
     */
    private $talkHelper;

    /**
     * @var CallForPapers
     */
    private $callForPapers;

    /**
     * @var HTMLPurifier
     */
    private $purifier;

    /**
     * @var Swift_Mailer
     */
    private $mailer;

    /**
     * @var string
     */
    private $applicationEmail;

    /**
     * @var string
     */
    private $applicationTitle;

    /**
     * @var string
     */
    private $applicationEndDate;

    public function __construct(
        Authentication $authentication,
        TalkHelper $talkHelper,
        CallForPapers $callForPapers,
        HTMLPurifier $purifier,
        Swift_Mailer $mailer,
        Twig_Environment $twig,
        UrlGeneratorInterface $urlGenerator,
        string $applicationEmail,
        string $applicationTitle,
        string $applicationEndDate
    ) {
        $this->authentication     = $authentication;
        $this->talkHelper         = $talkHelper;
        $this->callForPapers      = $callForPapers;
        $this->purifier           = $purifier;
        $this->mailer             = $mailer;
        $this->applicationEmail   = $applicationEmail;
        $this->applicationTitle   = $applicationTitle;
        $this->applicationEndDate = $applicationEndDate;

        parent::__construct($twig, $urlGenerator);
    }

    /**
     * @param $requestData
     *
     * @return TalkForm
     */
    private function getTalkForm($requestData): TalkForm
    {
        return new TalkForm($requestData, $this->purifier, [
            'categories' => $this->talkHelper->getTalkCategories(),
            'levels'     => $this->talkHelper->getTalkLevels(),
            'types'      => $this->talkHelper->getTalkTypes(),
        ]);
    }

    public function processCreateAction(Request $request): Response
    {
        // You can only create talks while the CfP is open
        if (!$this->callForPapers->isOpen()) {
            $request->getSession()->set('flash', [
                'type'  => 'error',
                'short' => 'Error',
                'ext'   => 'You cannot create talks once the call for papers has ended',
            ]);

            return $this->redirectTo('dashboard');
        }

        $user = $this->authentication->user();

        $form = $this->getTalkForm([
            'title'       => $request->get('title'),
            'description' => $request->get('description'),
            'type'        => $request->get('type'),
            'level'       => $request->get('level'),
            'category'    => $request->get('category'),
            'desired'     => $request->get('desired'),
            'slides'      => $request->get('slides'),
            'other'       => $request->get('other'),
            'sponsor'     => $request->get('sponsor'),
            'user_id'     => $request->get('user_id'),
        ]);
        $form->sanitize();

        if ($form->validateAll()) {
            $sanitizedData            = $form->getCleanData();
            $sanitizedData['user_id'] = (int) $user->getId();
            $talk                     = Talk::create($sanitizedData);

            $request->getSession()->set('flash', [
                'type'  => 'success',
                'short' => 'Success',
                'ext'   => 'Successfully saved talk.',
            ]);

            // send email to speaker showing submission
            $this->sendSubmitEmail($user->getLogin(), (int) $talk->id);

            return $this->redirectTo('dashboard');
        }

        $request->getSession()->set('flash', [
            'type'  => 'error',
            'short' => 'Error',
            'ext'   => \implode('<br>', $form->getErrorMessages()),
        ]);

        return $this->render('talk/create.twig', [
            'formAction'     => $this->url('talk_create'),
            'talkCategories' => $this->talkHelper->getTalkCategories(),
            'talkTypes'      => $this->talkHelper->getTalkTypes(),
            'talkLevels'     => $this->talkHelper->getTalkLevels(),
            'title'          => $request->get('title'),
            'description'    => $request->get('description'),
            'type'           => $request->get('type'),
            'level'          => $request->get('level'),
            'category'       => $request->get('category'),
            'desired'        => $request->get('desired'),
            'slides'         => $request->get('slides'),
            'other'          => $request->get('other'),
            'sponsor'        => $request->get('sponsor'),
            'buttonInfo'     => 'Submit my talk!',
            'flash'          => $request->getSession()->get('flash'),
        ]);
    }

    /**
     * Method that sends an email when a talk is created
     *
     * @param string $email
     * @param int    $talkId
     *
     * @return mixed
     */
    protected function sendSubmitEmail(string $email, int $talkId)
    {
        $talk = Talk::find($talkId, ['title']);

        // Build our email that we will send
        $template   = $this->twig->loadTemplate('emails/talk_submit.twig');
        $parameters = [
            'email'   => $this->applicationEmail,
            'title'   => $this->applicationTitle,
            'talk'    => $talk->title,
            'enddate' => $this->applicationEndDate,
        ];

        try {
            $message = new Swift_Message();

            $message->setTo($email);
            $message->setFrom(
                $template->renderBlock('from', $parameters),
                $template->renderBlock('from_name', $parameters)
            );

            $message->setSubject($template->renderBlock('subject', $parameters));
            $message->setBody($template->renderBlock('body_text', $parameters));
            $message->addPart(
                $template->renderBlock('body_html', $parameters),
                'text/html'
            );

            return $this->mailer->send($message);
        } catch (\Exception $e) {
            echo $e;
            die();
        }
    }
}
