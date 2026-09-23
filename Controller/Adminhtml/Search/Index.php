<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Controller\Adminhtml\Search;

use BigBridge\QuickNav\Model\Search\OpenSearch\OpenSearchAvailability;
use BigBridge\QuickNav\Model\Search\SearchService;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Must stay POST + form key, never GET + admin secret key: a secret key is bound to the session
 * that rendered Hyva's cached `search_url`, so it goes stale for every later session. The form
 * key is read fresh from `window.FORM_KEY` on every page load instead.
 */
class Index extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Backend::admin';

    public function __construct(
        Action\Context $context,
        private readonly SearchService $searchService,
        private readonly JsonFactory $jsonFactory,
        private readonly OpenSearchAvailability $openSearchAvailability,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $query = (string) $this->getRequest()->getParam('q', '');
        $bypassLimiter = (bool) $this->getRequest()->getParam('expand', false);
        // Type filter: posted as types[]=a&types[]=b by the UI's filter chips.
        $typeFilter = array_map('strval', (array) $this->getRequest()->getParam('types', []));
        $outcome = $this->searchService->search($query, $bypassLimiter, $typeFilter);

        return $this->jsonFactory->create()->setData([
            'results' => array_map(static fn ($result) => $result->toArray(), $outcome->results),
            'urlPasteAttempted' => $outcome->urlPasteAttempted,
            'urlPasteAccessDenied' => $outcome->urlPasteAccessDenied,
            'limiter' => $outcome->limiter !== null ? [
                'keyword' => $outcome->limiter->keyword,
                'label' => $outcome->limiter->label,
                'remainder' => $outcome->limiter->remainder,
            ] : null,
            // Lets the widget warn that the large-dataset types are on the slower unnarrowed scan,
            // and say which of the two causes applies rather than offering both.
            'openSearchStatus' => $this->openSearchAvailability->getStatus(),
        ]);
    }
}
