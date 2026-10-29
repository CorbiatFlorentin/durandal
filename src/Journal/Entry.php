<?php

declare(strict_types=1);

namespace Durandal\Journal;

use DateTimeImmutable;
use Durandal\Fault\ConnectionRefused;
use Durandal\Fault\Fault;
use Durandal\Fault\HttpError;
use Durandal\Fault\MalformedBody;
use Durandal\Fault\Timeout;
use Durandal\Fault\TruncatedBody;
use Durandal\Http\RequestInfo;

/**
 * Une requête passée par Durandal et ce qui lui est arrivé.
 */
final readonly class Entry
{
    /**
     * @param int $number position dans le journal, à partir de 1
     * @param ?string $body corps de la requête, s'il a pu être lu sans la perturber
     * @param ?Fault $fault panne appliquée (hors latence), null si la requête a été transmise intacte
     */
    public function __construct(
        public int $number,
        public DateTimeImmutable $at,
        public string $url,
        public RequestInfo $request,
        public ?string $body,
        public int $latencyMilliseconds,
        public ?Fault $fault,
    ) {}

    /**
     * La requête a atteint le serveur (intacte, ou avec une réponse altérée ensuite).
     */
    public function reachedServer(): bool
    {
        return $this->fault === null || $this->fault instanceof TruncatedBody || $this->fault instanceof MalformedBody;
    }

    /**
     * Le code testé a reçu une panne simulée.
     */
    public function failed(): bool
    {
        return $this->fault !== null;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->fault instanceof HttpError ? $this->fault->retryAfterSeconds : null;
    }

    /**
     * Identifie la requête logique dont cette tentative fait partie : méthode et URL.
     */
    public function target(): string
    {
        return $this->request->method . ' ' . $this->url;
    }

    public function describe(): string
    {
        return \sprintf(
            '#%d  %s  %s%s  → %s',
            $this->number,
            $this->at->format('H:i:s.v'),
            $this->target(),
            $this->latencyMilliseconds > 0 ? \sprintf('  (+%d ms)', $this->latencyMilliseconds) : '',
            self::outcome($this->fault),
        );
    }

    private static function outcome(?Fault $fault): string
    {
        return match (true) {
            $fault === null => 'transmise au serveur',
            $fault instanceof HttpError => \sprintf('%d simulé', $fault->status)
                . ($fault->retryAfterSeconds === null ? '' : \sprintf(' (Retry-After : %d s)', $fault->retryAfterSeconds)),
            $fault instanceof Timeout => 'délai dépassé simulé',
            $fault instanceof ConnectionRefused => 'refus de connexion simulé',
            $fault instanceof TruncatedBody => \sprintf('transmise, corps tronqué à %d %%', (int) round($fault->keepRatio * 100)),
            $fault instanceof MalformedBody => 'transmise, corps remplacé par un contenu invalide',
            default => 'panne ' . $fault::class,
        };
    }
}
