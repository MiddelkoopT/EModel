#!/bin/sh

FILES='Brandon.osil Brandon.osrl Brandon.csv'

touch ${FILES}
chgrp www-data ${FILES}
chmod g+w ${FILES}
