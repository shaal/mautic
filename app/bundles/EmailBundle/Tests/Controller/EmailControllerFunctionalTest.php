<?php

declare(strict_types=1);

/*
 * @copyright   2020 Mautic Contributors. All rights reserved
 * @author      Mautic.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\EmailBundle\Tests\Controller;

use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use PHPUnit\Framework\Assert;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Component\HttpFoundation\Request;

final class EmailControllerFunctionalTest extends MauticMysqlTestCase
{
    public function setUp(): void
    {
        $this->clientOptions = ['debug' => true];

        parent::setUp();
    }

    /**
     * Ensure there is no query for DNC reasons if there are no contacts who received the email
     * because it loads the whole DNC table if no contact IDs are provided. It can lead to
     * memory limit error if the DNC table is big.
     *
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function testProfileEmailDetailPageForUnsentEmail(): void
    {
        $segment = $this->createSegment();
        $email   = $this->createEmail();
        $email->addList($segment);

        $this->em->persist($segment);
        $this->em->persist($email);
        $this->em->flush();

        $this->client->enableProfiler();
        $this->client->request(Request::METHOD_GET, "/s/emails/view/{$email->getId()}");

        $profile = $this->client->getProfile();

        /** @var DoctrineDataCollector $dbCollector */
        $dbCollector = $profile->getCollector('db');
        $queries     = $dbCollector->getQueries();
        $prefix      = self::$container->getParameter('mautic.db_table_prefix');

        $dncQueries = array_filter(
            $queries['default'],
            fn (array $query) => "SELECT l.id, dnc.reason FROM {$prefix}lead_donotcontact dnc LEFT JOIN {$prefix}leads l ON l.id = dnc.lead_id WHERE dnc.channel = :channel" === $query['sql']
        );

        Assert::assertCount(0, $dncQueries);
    }

    /**
     * On the other hand there should be the query for DNC reasons if there are contacts who received the email.
     *
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function testProfileEmailDetailPageForSentEmail(): void
    {
        $segment = $this->createSegment();
        $email   = $this->createEmail();
        $email->addList($segment);
        $contact = new Lead();
        $contact->setEmail('john@doe.email');
        $emailStat = new Stat();
        $emailStat->setEmail($email);
        $emailStat->setLead($contact);
        $emailStat->setEmailAddress($contact->getEmail());
        $emailStat->setDateSent(new \DateTime());
        $this->em->persist($segment);
        $this->em->persist($email);
        $this->em->persist($contact);
        $this->em->persist($emailStat);
        $this->em->flush();

        $this->client->enableProfiler();
        $this->client->request(Request::METHOD_GET, "/s/emails/view/{$email->getId()}");

        $profile = $this->client->getProfile();

        /** @var DoctrineDataCollector $dbCollector */
        $dbCollector = $profile->getCollector('db');
        $queries     = $dbCollector->getQueries();
        $prefix      = self::$container->getParameter('mautic.db_table_prefix');

        $dncQueries = array_filter(
            $queries['default'],
            fn (array $query) => "SELECT l.id, dnc.reason FROM {$prefix}lead_donotcontact dnc LEFT JOIN {$prefix}leads l ON l.id = dnc.lead_id WHERE (dnc.channel = :channel) AND (l.id IN ({$contact->getId()}))" === $query['sql']
        );

        Assert::assertCount(1, $dncQueries, 'DNC query not found. '.var_export(array_map(fn (array $query) => $query['sql'], $queries['default']), true));
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function testSegmentEmailTranslationLookUp(): void
    {
        $segment = $this->createSegment();
        $email   = $this->createEmail();
        $email->addList($segment);

        $this->em->persist($segment);
        $this->em->persist($email);
        $this->em->flush();

        $crawler = $this->client->request(Request::METHOD_GET, '/s/emails/new');
        $html    = $crawler->filterXPath("//select[@id='emailform_segmentTranslationParent']//optgroup")->html();
        self::assertSame('<option value="'.$email->getId().'">'.$email->getName().'</option>', trim($html));
    }

    private function createSegment(string $suffix = 'A'): LeadList
    {
        $segment = new LeadList();
        $segment->setName("Segment $suffix");
        $segment->setPublicName("Segment $suffix");
        $segment->setAlias("segment-$suffix");

        return $segment;
    }

    private function createEmail(string $suffix = 'A', string $emailType = 'list')
    {
        $email = new Email();
        $email->setName("Email $suffix");
        $email->setSubject("Email $suffix Subject");
        $email->setEmailType($emailType);

        return $email;
    }

    public function testEmailDetailsPageShouldNotHavePendingCount(): void
    {
        // Create a segment
        $segment = new LeadList();
        $segment->setName('Test Segment A');
        $segment->setPublicName('Test Segment A');
        $segment->setAlias('test-segment-a');

        // Create email template of type "list" and attach the segment to it
        $email = new Email();
        $email->setName('Test Email C');
        $email->setSubject('Test Email C Subject');
        $email->setEmailType('list');
        $email->addList($segment);

        $this->em->persist($segment);
        $this->em->persist($email);
        $this->em->flush();

        $this->client->enableProfiler();
        $crawler = $this->client->request(Request::METHOD_GET, "/s/emails/view/{$email->getId()}");

        // checking if pending count is removed from details page ui
        $emailDetailsContainer = trim($crawler->filter('#email-details')->filter('tbody')->text());
        $this->assertStringNotContainsString('Pending', $emailDetailsContainer);

        $profile = $this->client->getProfile();

        /** @var DoctrineDataCollector $dbCollector */
        $dbCollector = $profile->getCollector('db');
        $queries     = $dbCollector->getQueries();
        $prefix      = self::$container->getParameter('mautic.db_table_prefix');

        $pendingCountQuery = array_filter(
            $queries['default'],
            fn (array $query) => $query['sql'] === "SELECT count(*) as count FROM {$prefix}leads l WHERE (EXISTS (SELECT null FROM {$prefix}lead_lists_leads ll WHERE (ll.lead_id = l.id) AND (ll.leadlist_id IN ({$segment->getId()})) AND (ll.manually_removed = :false))) AND (NOT EXISTS (SELECT null FROM {$prefix}lead_donotcontact dnc WHERE (dnc.lead_id = l.id) AND (dnc.channel = 'email'))) AND (NOT EXISTS (SELECT null FROM {$prefix}email_stats stat WHERE (stat.lead_id = l.id) AND (stat.email_id IN ({$email->getId()})))) AND (NOT EXISTS (SELECT null FROM {$prefix}message_queue mq WHERE (mq.lead_id = l.id) AND (mq.status <> 'sent') AND (mq.channel = 'email') AND (mq.channel_id IN ({$email->getId()})))) AND ((l.email IS NOT NULL) AND (l.email <> ''))"
        );

        $this->assertCount(0, $pendingCountQuery);
    }
}
