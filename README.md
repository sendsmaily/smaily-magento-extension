Smaily Sync for Magento 2

A Magento extension that allows you to export newsletter subscribers from your Magento administration interface to Smaily.
NOTE! One of the most noticeable shortcoming of the extension is that it allows only one-way synchronization, i.e. subscribers can only be exported from Magento to Smaily. It will be addressed in the next major version, after Smaily has added the capability for data export through the API.

- Installation
1.    Make sure you have Magento 2.0 and above installed.
2.    Download the latest release from GitHub.
3.    Extract the archive to app/code/Magento/Smaily directory.
4.    Run "php bin/magento setup:upgrade".
5.  Extension configuration can be found from Magento administration interface, under "Stores → Configuration → Smaily Email Marketing and Automation".

To export specific subscriber(s), filter desired subscribers using the fields under the table header and click Export.
NOTE! All filtered subscribers are exported, selecting/marking row(s) does not export that/these row(s).

- Troubleshooting
Regular export fails to run 
  Usually a good place to start would be to check Magento CRON's Schedule Ahead for value. We have found that value of 60 works the best, if you are running daily exports.