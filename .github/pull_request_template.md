## O que muda

<!-- Descreva a alteração na aplicação. -->

## Checklist

- [ ] `./vendor/bin/pint --test` OK
- [ ] `./vendor/bin/phpstan analyse` OK
- [ ] `php artisan test` (Pest) verde
- [ ] `kubectl kustomize k8s/overlays/prod` builda
- [ ] Nenhum segredo commitado (.env, chaves, JWT)
- [ ] PR direcionado a `homolog` (ou de `homolog` para `master`)
