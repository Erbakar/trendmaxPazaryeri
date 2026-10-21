<?php
/**
 * OzyConnect Panel — UpdateController (Merchant: güncelleme/changelog listesi)
 *
 * Mağaza yayınlanmış güncellemeleri salt-okunur görür. Görüntülenince "son görülen" işaretlenir
 * → nav'daki okunmamış rozeti sıfırlanır. Yayınlama admin tarafındadır (AdminUpdateController).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\AppUpdate;
use App\Models\UpdateRead;

final class UpdateController extends BaseController
{
    private readonly AppUpdate $updates;
    private readonly UpdateRead $reads;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->updates = $container->get(AppUpdate::class);
        $this->reads   = $container->get(UpdateRead::class);
    }

    public function index(Request $request): void
    {
        $list = $this->updates->published();

        // Görüldü olarak işaretle (nav rozetini sıfırlar)
        $uid = (int) $this->session->userId();
        if ($uid > 0) {
            $this->reads->markSeen($uid, $this->updates->latestPublishedId());
        }

        $this->view('updates.index', [
            'pageTitle' => 'Güncellemeler',
            'updates'   => $list,
        ]);
    }
}
