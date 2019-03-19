<?php declare(strict_types=1);

/**
 * Einrichtungshaus Ostermann GmbH & Co. KG - Order Status
 *
 * @package   OstOrderStatus
 *
 * @author    Eike Brandt-Warneke <e.brandt-warneke@ostermann.de>
 * @copyright 2019 Einrichtungshaus Ostermann GmbH & Co. KG
 * @license   proprietary
 */

namespace OstOrderStatus\Commands;

use Enlight_Components_Db_Adapter_Pdo_Mysql as Db;
use Shopware\Commands\ShopwareCommand;
use Shopware\Components\Model\ModelManager;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncOrderStatus extends ShopwareCommand
{
    /**
     * ...
     *
     * @var Db
     */
    private $db;

    /**
     * ...
     *
     * @var ModelManager
     */
    private $modelManager;

    /**
     * ...
     *
     * @var array
     */
    private $configuration;

    /**
     * From index to another status within the array.
     *
     * @var array
     */
    private $validStatus = [
        0 => [1, 2, 3, 4, 5, 6, 7, 8],
        1 => [2, 3, 4, 5, 6, 7, 8],
        2 => [],
        3 => [2, 7, 8],
        4 => [],
        5 => [2, 3, 6, 7, 8],
        6 => [2, 7, 8],
        7 => [2],
        8 => []
    ];

    /**
     * Mapping from iwm status to shopware status.
     *
     * @var array
     */
    private $mapping = [];

    /**
     * @param Db           $db
     * @param ModelManager $modelManager
     * @param array        $configuration
     */
    public function __construct(Db $db, ModelManager $modelManager, array $configuration)
    {
        parent::__construct();
        $this->db = $db;
        $this->modelManager = $modelManager;
        $this->configuration = $configuration;
        $this->mapping = $this->getMapping();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // ...
        $output->writeln('reading .csv file: ' . rtrim($this->configuration['csvDirectory'], '/') . '/' . $this->configuration['csvFilename']);

        // ...
        $file = file_get_contents(rtrim($this->configuration['csvDirectory'], '/') . '/' . $this->configuration['csvFilename']);

        // make it an array
        $lines = array_map('trim', explode('<br>', nl2br($file, false)));

        // ...
        $output->writeln('processing orders');

        // start the progress bar
        $progressBar = new ProgressBar($output, count($lines));
        $progressBar->setRedrawFrequency(10);
        $progressBar->start();

        // ...
        $counter = [
            'lines'               => 0,
            'invalid-lines'       => 0,
            'status-not-found'    => 0,
            'not-found'           => 0,
            'changed'             => 0,
            'same-status'         => 0,
            'invalid-pre-status'  => 0,
            'invalid-post-status' => 0
        ];

        // ...
        foreach ($lines as $line) {
            // advance progress bar
            $progressBar->advance();

            // ...
            ++$counter['lines'];

            // split it
            $split = explode('|', $line);

            // ...
            if (count($split) !== 2) {
                // count
                ++$counter['invalid-lines'];

                // and next
                continue;
            }

            // set params
            $number = (int) $split[0];
            $status = (int) $split[1];

            // ...
            if ($number === 0) {
                // count
                ++$counter['invalid-lines'];

                // and next
                continue;
            }

            // get the shopware status
            $shopwareStatus = $this->getShopwareStatus($status);

            // not in valid status?!
            if ($shopwareStatus === false) {
                // count
                ++$counter['status-not-found'];

                // and next
                continue;
            }

            // get the order id
            $query = '
                SELECT id, status
                FROM s_order
                WHERE ordernumber = :number
            ';
            $order = $this->db->fetchRow($query, ['number' => $number]);

            // not found?
            if (!is_array($order)) {
                // count
                ++$counter['not-found'];

                // and next
                continue;
            }

            // set params
            $id = (int) $order['id'];
            $preStatus = (int) $order['status'];

            // is this the same status?!
            if ($preStatus === $shopwareStatus) {
                // count
                ++$counter['same-status'];

                // and next
                continue;
            }

            // is our new status valid?!
            if (!isset($this->validStatus[$preStatus])) {
                // count
                ++$counter['invalid-pre-status'];

                // and next
                continue;
            }

            // our post status valid?
            if (!in_array($shopwareStatus, $this->validStatus[$preStatus])) {
                // count
                ++$counter['invalid-post-status'];

                // and next
                continue;
            }

            // set it
            Shopware()->Modules()->Order()->setOrderStatus($id, $shopwareStatus, false, null);

            // count
            ++$counter['changed'];
        }

        // done
        $progressBar->finish();
        $output->writeln('');

        // ...
        foreach ($counter as $key => $value) {
            // show every counter
            $output->writeln($key . ': ' . $value);
        }
    }

    /**
     * ...
     *
     * @param int $status
     *
     * @return int|false
     */
    private function getShopwareStatus($status)
    {
        // is it set?
        if (!isset($this->mapping[$status])) {
            // mooooep
            return false;
        }

        // return the shopware status
        return (int) $this->mapping[$status];
    }

    /**
     * ...
     *
     * @return array
     */
    private function getMapping()
    {
        // get the mapping
        $arr = array_map('trim', explode('<br>', nl2br($this->configuration['mapping'], false)));

        // ...
        $mapping = [];

        // ...
        foreach ($arr as $aktu) {
            // split both status
            $tmp = explode(':', $aktu);

            // invalid?
            if (count($tmp) !== 2) {
                // next
                continue;
            }

            // set the mapping
            $mapping[(int) $tmp[0]] = (int) $tmp[1];
        }

        // return the mapping
        return $mapping;
    }
}
