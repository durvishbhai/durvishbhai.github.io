<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\DeliverablePolicy;
use Vertex\Services\DeliverableService;
use Vertex\Services\PermissionSync;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;

/**
 * People and a project shared by the Phase 3 suites.
 *   admin (administrator)  manager (assigned, lead)  manager2 (NOT assigned)  dev (assigned worker)  dev2 (worker on another project)
 *   support (assigned)  client (owns $alpha)  client2 (owns $bravo)  partner (referral partner)
 */
trait Phase3Fixtures
{
    private int $admin;
    private int $manager;
    private int $manager2;
    private int $dev;
    private int $dev2;
    private int $support;
    private int $client;
    private int $client2;
    private int $partner;
    /** @var array{id:int,public_id:string} */
    private array $alpha;
    /** @var array{id:int,public_id:string} */
    private array $bravo;
    /** @var list<string> temp files to delete */
    private array $tmpFiles = [];

    protected function setUpPhase3(): void
    {
        PermissionSync::run(true);
        $this->admin = $this->makeUser('admin@x.test', ['administrator']);
        $this->manager = $this->makeUser('mgr@x.test', ['manager']);
        $this->manager2 = $this->makeUser('mgr2@x.test', ['manager']);
        $this->dev = $this->makeUser('dev@x.test', ['website_developer']);
        $this->dev2 = $this->makeUser('dev2@x.test', ['website_developer']);
        $this->support = $this->makeUser('sup@x.test', ['support_staff']);
        $this->client = $this->makeUser('alpha@client.test', ['client']);
        $this->client2 = $this->makeUser('bravo@client.test', ['client']);
        $this->partner = $this->makeUser('partner@x.test', ['referral_partner']);
        $this->alpha = $this->makeProject($this->client, 'Alpha Website');
        $this->bravo = $this->makeProject($this->client2, 'Bravo Video');
        Database::run("UPDATE projects SET service_category = 'website_development' WHERE id = ?", [$this->alpha['id']]);
        foreach ([$this->manager, $this->dev, $this->support] as $u) {
            ProjectAssignmentService::assign($this->admin, $this->alpha['id'], $u, 'member');
        }
        ProjectAssignmentService::assign($this->admin, $this->bravo['id'], $this->dev2, 'member');
    }

    protected function tearDownPhase3(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $this->tmpFiles = [];
    }

    /** @return array<string,mixed> */
    protected function alphaRow(): array
    {
        return ProjectPolicy::find($this->alpha['public_id']);
    }

    /** A real 1x1 PNG in a temp file, optionally padded so it is unique. @return array{name:string,tmp_name:string,size:int,error:int} */
    protected function png(string $name = 'design.png', string $pad = ''): array
    {
        $bytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        return $this->tmp($name, $bytes . $pad);
    }

    /** @return array{name:string,tmp_name:string,size:int,error:int} */
    protected function tmp(string $name, string $bytes): array
    {
        $p = tempnam(sys_get_temp_dir(), 'vtx');
        file_put_contents($p, $bytes);
        $this->tmpFiles[] = $p;

        return ['name' => $name, 'tmp_name' => $p, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK];
    }

    /** Creates a deliverable (draft V1) as $actor; returns its row. @param array<string,mixed> $over @return array<string,mixed> */
    protected function makeDeliverable(array $over = [], ?int $actor = null, ?array $file = null): array
    {
        $res = DeliverableService::create($actor ?? $this->manager, $this->alphaRow(), $over + ['title' => 'Homepage design', 'category' => 'graphic_design'], $file ?? $this->png(), false);

        return DeliverablePolicy::find($res['public_id']);
    }

    /** @return array<string,mixed> */
    protected function freshD(array $d): array
    {
        return DeliverablePolicy::find($d['public_id']);
    }

    /** @return array<string,mixed> version row by number */
    protected function ver(array $d, int $no): array
    {
        return Database::one('SELECT v.*, f.original_name, f.size_bytes, f.mime FROM deliverable_versions v LEFT JOIN stored_files f ON f.id = v.file_id WHERE v.deliverable_id = ? AND v.version_no = ?', [$d['id'], $no]);
    }

    /** Takes V$no from draft to published as manager (uploader) + admin (reviewer/publisher). @return array<string,mixed> the published version */
    protected function publishVersion(array $d, int $no = 1): array
    {
        $v = $this->ver($d, $no);
        if (in_array($v['status'], ['draft', 'changes_requested'], true)) {
            DeliverableService::submitForReview($this->manager, $this->freshD($d), $v);
            $v = $this->ver($d, $no);
        }
        DeliverableService::review($this->admin, $this->freshD($d), $v, 'approved', 'Looks right');
        DeliverableService::publish($this->admin, $this->freshD($d), $this->ver($d, $no));

        return $this->ver($d, $no);
    }
}
