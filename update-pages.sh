#!/usr/bin/env bash

set -Eeuo pipefail

echo "::group::Prepare git commit"
git add docs
echo 'Added updated files'

git config --global user.name 'System'
git config --global user.email 'system <>'
echo 'Set up committer username and e-mail'

echo 'Staged diff summary:'
git diff --cached --stat || true
echo "::endgroup::"

echo "::group::Commit and push"
git commit --allow-empty -m 'Update GitHub Pages subtree'
echo 'Committed changes'

git push
echo 'Pushed to master'
echo "::endgroup::"
