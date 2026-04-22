#!/bin/bash
# Lightsail SSH 콘솔에서 실행할 배포 스크립트
# 사용법: curl -sL https://raw.githubusercontent.com/ehrok/pc-deploy-temp/main/deploy.sh | bash

DEST=/opt/bitnami/apache/htdocs/ProjectCenter
REPO=https://raw.githubusercontent.com/ehrok/pc-deploy-temp/main

echo "=== ProjectCenter Deploy ==="
echo "1. Backing up..."
cp $DEST/index.php $DEST/index.php.bak_$(date +%Y%m%d%H%M%S) 2>/dev/null
cp $DEST/api_update.php $DEST/api_update.php.bak_$(date +%Y%m%d%H%M%S) 2>/dev/null

echo "2. Downloading files..."
curl -sL $REPO/index.php -o $DEST/index.php
curl -sL $REPO/api_update.php -o $DEST/api_update.php

echo "3. Verifying PHP syntax..."
php -l $DEST/index.php
php -l $DEST/api_update.php

echo "4. Checking file encoding..."
file $DEST/index.php
file $DEST/api_update.php

echo "5. Fixing permissions..."
chmod 644 $DEST/index.php $DEST/api_update.php

echo ""
echo "=== Deploy Complete ==="
echo "Test command:"
echo "curl -u hrk:teamhrk http://localhost/ProjectCenter/index.php?p=busan-yongho6 | grep '핵심 미팅'"
