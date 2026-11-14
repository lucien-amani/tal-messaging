<?php
declare(strict_types=1);

namespace App\Messaging\Providers;

/** Contrat d'un fournisseur d'envoi. Le routeur (module suivant) choisira entre plusieurs implémentations. */
interface ProviderInterface
{
    public function name(): string;

    /**
     * @param array{id:int,to:string,from:string,body:string,operator:?string,country:?string} $message
     * @return array{status:string,provider_message_id:?string,error_code:?string,error_message:?string,retryable:bool}
     *         status : 'sent' (accepté), 'delivered' (accusé reçu) ou 'failed'.
     *         Échec : retryable = nouvelle tentative utile ; code PROVIDER_* = problème de compte/fournisseur (le router bascule sur le suivant)
     */
    public function send(array $message): array;

    /** Vérifie les identifiants sans envoyer de SMS. @return array{ok:bool,message:string} */
    public function test(): array;
}
