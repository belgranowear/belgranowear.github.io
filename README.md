# BelgranoWear (content repository)

This repository hosts the static information provisioning backend for the **BelgranoWear** application. The frontend can be found at [belgranowear/BelgranoWear](https://github.com/belgranowear/BelgranoWear).

**BelgranoWear** is an application that lets you travel through the **Belgrano Norte** network (maintained by **Ferrovias**).

## System requirements
- A GNU/Linux-based operating system
- Docker
- An internet connection

## Usage

- Clone this repository to your local machine:

    `git clone https://github.com/belgranowear/belgranowear.github.io`

- Navigate to the cloned directory:

    `cd belgranowear.github.io`

- Regenerate the files:

    `docker compose up --build`

## Deployment

To deploy the site, push the contents of the `docs` directory to the `gh-pages` branch:

    git subtree push --prefix docs origin gh-pages

## License

**BelgranoWear** is open-sourced software licensed under the [MIT License](LICENSE).

## Contributing

Contributions to improve **BelgranoWear** are welcome. To contribute, please follow these steps:

1. Fork the repository.
2. Create a new branch for your feature or bugfix.
3. Commit your changes with clear and descriptive messages.
4. Push your changes to your fork.
5. Open a pull request with a detailed description of your changes.