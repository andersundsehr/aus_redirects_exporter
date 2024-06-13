# Usage

    # fill sys_redirect.tx_ausredirects_exporter_resolved column
    typo3 andersundsehr:redirects-expoter:export
    # creates $HOST.txt file on your project root and writes nginx rulesets which were generated in sys_redirect.tx_ausredirects_exporter_resolved previously
    typo3 andersundsehr:redirects-expoter:writer

If you want to clear the column to recreate all sys_redirect.tx_ausredirects_exporter_resolved again and not just the ones holding null

    typo3 andersundsehr:redirects-expoter:clear

If you start the job again it will try to solve all entries which are null or old

## Configuration

The configuration needs three places which do not exist yet or which are empty. That means the extension *clears all files* in the desired directories!

The directory will contain the final rules which can include in nginx.

The directoryNew and directoryOld will contain temporary data.

Also the directorys need to be moveable, which may cause problems over partitions/docker volumes.

# Future
- we plan to export the entries to a configurable file included in nginx directly

# with ♥️ from anders und sehr GmbH

> If something did not work 😮  
> or you appreciate this Extension 🥰 let us know.

> We are hiring https://www.andersundsehr.com/karriere/

