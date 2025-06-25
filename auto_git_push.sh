#!/bin/bash

while true
do
  git add .
  git commit -m "Auto update on $(date +'%Y-%m-%d %H:%M:%S')" > /dev/null 2>&1
  git push origin main > /dev/null 2>&1
  sleep 60  # ينتظر دقيقة قبل التحديث التالي
done

