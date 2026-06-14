#!/bin/sh

#
# Copyright (c) 2026. Esup - Université de Bordeaux.
#
# This file is part of the Esup-Oasis project (https://github.com/EsupPortail/esup-oasis).
#  For full copyright and license information please view the LICENSE file distributed with the source code.
#
#  @author Manuel Rossard <manuel.rossard@u-bordeaux.fr>
#
#


# Déclencher les migrations de schéma
php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

exec "$@"
