<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ResourceModel\Automation\Mapping;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Smaily\Connect\Model\Automation\Mapping;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;

class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(Mapping::class, MappingResource::class);
    }

    /**
     * Keeps only the rows of the accounts the given multilingual mode sends
     * through (CON-184). A workflow id belongs to the Smaily account it was
     * picked from: mode A's rows name their language's own account, mode B's
     * rows the website's one account ('default'). A website that leaves one
     * of these modes keeps its rows, so a switch back finds them again, but
     * the other mode never reads them. In mode A the rows without a language
     * (the 2.8.x migration's fallback rows, account 'default') are kept too.
     *
     * @param string $mode MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS, or any
     *        other mode for the rows of the website's one account
     * @return $this
     */
    public function addModeFilter(string $mode): self
    {
        if ($mode === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS) {
            return $this->addFieldToFilter(
                ['account_key', 'language'],
                [['neq' => Mapping::ACCOUNT_DEFAULT], ['eq' => Mapping::LANGUAGE_DEFAULT]]
            );
        }

        return $this->addFieldToFilter('account_key', Mapping::ACCOUNT_DEFAULT);
    }

    /**
     * Leaves out the store-wide rows (website 0) that a website's own row
     * of the same trigger, language and account hides (CON-396): the
     * website's own row is read in their place, also where it holds no
     * workflow (MappingSaver's row of a cleared workflow). Website 0 hides
     * nothing.
     *
     * @return $this
     */
    public function addHiddenSharedRowsFilter(int $websiteId): self
    {
        if ($websiteId <= 0) {
            return $this;
        }
        $own = $this->getConnection()->select()
            ->from(['own' => $this->getMainTable()], ['id'])
            ->where('own.website_id = ?', $websiteId)
            ->where('own.trigger_type = main_table.trigger_type')
            ->where('own.language = main_table.language')
            ->where('own.account_key = main_table.account_key');
        $this->getSelect()->where('main_table.website_id <> 0 OR NOT EXISTS (' . $own->assemble() . ')');

        return $this;
    }
}
