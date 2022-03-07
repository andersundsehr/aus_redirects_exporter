# Usage

    # fill sys_redirect.tx_ausredirects_exporter_resolved column
    typo3 andersundsehr:redirects-expoter:export
    # creates $HOST.txt file on your project root and writes nginx rulesets which were generated in sys_redirect.tx_ausredirects_exporter_resolved previously
    typo3 andersundsehr:redirects-expoter:writer

If you want to clear the column to recreate all sys_redirect.tx_ausredirects_exporter_resolved again and not just the ones holding null

    typo3 andersundsehr:redirects-expoter:clear

If you start the job again it will try to solve all entries which are null or old

# Future
- we plan to export the entries to a configurable file included in nginx directly
