#!/bin/bash

git add docs || exit 1;
echo 'Added updated files';

git config --global user.name 'System';
git config --global user.email 'system <>';
echo 'Set up committer username and e-mail';

git commit --allow-empty -m 'Update GitHub Pages subtree';
echo 'Committed changes';

git push;
echo 'Pushed to master';